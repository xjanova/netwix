<?php

namespace App\Services;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Download this episode onto storage we control" for progressive (MP4) sources — resolves the
 * episode's current signed URL, downloads it, writes it to the configured disk, and points the episode
 * at the stored copy. After that playback serves our file and never asks the source for a fresh link.
 *
 * The disk is `services.ingest.disk`: this server's own disk, or Cloudflare R2. Two rules make a
 * mixed catalogue safe, and both exist because getting them wrong costs money quietly rather than
 * loudly:
 *
 *   1. **Every episode records the disk and the exact object key its file went to.** Reconstructing a
 *      path at delete time breaks the moment a re-import renumbers an episode, and on remote storage a
 *      failed delete is not an error — it is a file that bills forever and no longer appears in any
 *      total, because the same operation nulls `file_size`.
 *   2. **A stored file is verified to be a plausible episode before it is stored**, against the rest of
 *      the title rather than a fixed floor. rongyok's id-1319 ends with a 0.2 MB stub, twenty times the
 *      old 10 KB minimum: large enough to pass, far too small to be an episode.
 *
 * HLS sources still can't be mirrored here — they'd need ffmpeg to remux thousands of segments into
 * one file — so they keep streaming on demand.
 */
class MediaMirror
{
    /** Absolute floor: below this it is an error page or a truncated download, never a video. */
    private const MIN_BYTES = 500_000;

    /** …and it must also be at least this share of the title's typical episode. */
    private const MIN_SHARE_OF_TYPICAL = 0.25;

    private const MAX_BYTES = 700_000_000;   // 700MB hard cap per file

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct(private SourceRegistry $registry) {}

    /** The disk mirrored files are written to right now. */
    public static function disk(): string
    {
        return (string) config('services.ingest.disk', 'public');
    }

    /**
     * Download one episode onto our storage. @return array{ok:bool,bytes?:int,error?:string}
     */
    public function store(Episode $episode): array
    {
        $r = $this->attempt($episode);

        // Count the failure on the episode HERE rather than in the caller. The worker used to do it and
        // the admin button did not, so a systematically failing episode could be retried from the admin
        // page forever — re-downloading the whole file from the source every time.
        if (! $r['ok']) {
            $episode->increment('mirror_attempts');
            $episode->update(['mirror_failed_at' => now()]);
        }

        return $r;
    }

    /** @return array{ok:bool,bytes?:int,error?:string} */
    private function attempt(Episode $episode): array
    {
        $content = $episode->content;
        if (! $content?->source || ! $content->source_key || ! $episode->source_ref) {
            return ['ok' => false, 'error' => 'ตอนนี้ไม่มีแหล่งที่มาให้ดาวน์โหลด'];
        }
        $source = $this->registry->get($content->source);
        if (! $source) {
            return ['ok' => false, 'error' => 'ไม่รู้จักแหล่งที่มา'];
        }
        if (! $source->isProgressive()) {
            return ['ok' => false, 'error' => 'แหล่งนี้เป็นสตรีม HLS — ยังไม่รองรับการโหลดเก็บ (ต้องใช้ ffmpeg)'];
        }

        $disk = self::disk();
        if (! $this->hasRoom()) {
            return ['ok' => false, 'error' => 'พื้นที่จัดเก็บเต็มเพดาน หรือดิสก์เซิร์ฟเวอร์ใกล้เต็ม'];
        }
        // A disk we cannot serve from would store bytes nobody can play — a paid-for dead catalogue.
        if ($disk !== 'public' && ! $this->publicUrlConfigured($disk)) {
            return ['ok' => false, 'error' => "ดิสก์ '{$disk}' ยังไม่ได้ตั้งค่า URL สาธารณะ — ไฟล์ที่เก็บจะเล่นไม่ได้"];
        }

        // Resolve HERE, one episode at a time. rongyok hands back a signed Discord CDN URL that expires
        // in about a day, so a run that resolved all 214 episodes up front would find the tail dead.
        $stream = $source->resolveByRef((string) $content->source_key, (string) $episode->source_ref);
        if (! $stream || $stream->kind !== RemoteStream::KIND_MP4 || $stream->url === '') {
            return ['ok' => false, 'error' => 'แหล่งต้นทางไม่พร้อม (ลิงก์อาจหมุนไป) — ลองใหม่อีกครั้ง'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nxmir');
        try {
            $resp = Http::withHeaders(['User-Agent' => self::UA])->timeout(300)->sink($tmp)->get($stream->url);
            if (! $resp->ok()) {
                return ['ok' => false, 'error' => 'ดาวน์โหลดไม่สำเร็จ (HTTP '.$resp->status().')'];
            }

            $size = (int) (@filesize($tmp) ?: 0);
            if ($reject = $this->rejectReason($episode, $size)) {
                return ['ok' => false, 'error' => $reject];
            }

            // The key keeps its .mp4 suffix deliberately: the S3 adapter derives Content-Type from the
            // key, and an extensionless object lands as application/octet-stream, which iOS AVPlayer
            // refuses to play while Android happily does — a bug that would only surface on one
            // platform, long after the upload.
            $key = "media/{$content->source}/{$content->source_key}/{$episode->number}.mp4";

            Storage::disk($disk)->putFileAs(dirname($key), new File($tmp), basename($key));

            $episode->update([
                'video_url' => Storage::disk($disk)->url($key),
                'mirrored_at' => now(),
                // The size we already measured locally. Asking the disk for it would be a second
                // network round-trip on remote storage, and a throw there would leave an uploaded
                // object that no row records — an orphan with no key to find it by.
                'file_size' => $size,
                'mirror_disk' => $disk,
                'mirror_key' => $key,
                'mirror_trigger' => 'admin',
                'mirror_attempts' => 0,
                'mirror_failed_at' => null,
            ]);

            return ['ok' => true, 'bytes' => $size];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'ผิดพลาด: '.mb_substr($e->getMessage(), 0, 100)];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Why this download is not a usable episode, or null if it is.
     *
     * The comparison is against the title's other stored episodes rather than a constant, because
     * "too small" only means anything relative to the show: 7 MB is a healthy rongyok vertical short
     * and a broken anime episode.
     */
    private function rejectReason(Episode $episode, int $size): ?string
    {
        $mb = fn (int $b) => number_format($b / 1e6, 1);

        if ($size < self::MIN_BYTES) {
            return 'ไฟล์เล็กผิดปกติ ('.$mb($size).' MB) — น่าจะเป็นหน้า error หรือโหลดไม่ครบ';
        }
        if ($size > self::MAX_BYTES) {
            return 'ไฟล์ใหญ่เกินเพดาน ('.$mb($size).' MB)';
        }

        $typical = $this->typicalBytes($episode);
        if ($typical !== null && $size < $typical * self::MIN_SHARE_OF_TYPICAL) {
            return 'ไฟล์เล็กกว่าตอนอื่นของเรื่องนี้มาก ('.$mb($size).' MB เทียบกับปกติ '.$mb($typical).' MB) — ข้ามไว้ก่อน';
        }

        return null;
    }

    /** Median-ish size of this title's already-stored episodes, or null when there aren't enough yet. */
    private function typicalBytes(Episode $episode): ?int
    {
        $row = DB::table('episodes')
            ->where('content_id', $episode->content_id)
            ->whereNotNull('mirrored_at')
            ->where('id', '<>', $episode->id)
            ->selectRaw('COUNT(*) n, AVG(file_size) a')
            ->first();

        return ($row && (int) $row->n >= 3 && $row->a > 0) ? (int) round($row->a) : null;
    }

    /** Would a viewer be able to fetch a file from this disk? */
    private function publicUrlConfigured(string $disk): bool
    {
        $url = (string) config("filesystems.disks.{$disk}.url", '');

        // The bucket's own S3 endpoint is NOT a public URL — it answers 401 to a viewer. Only a custom
        // domain (or another front door we've deliberately set) makes an object playable.
        return $url !== '' && ! str_contains($url, 'r2.cloudflarestorage.com');
    }

    /** Delete a stored file and revert the episode to on-demand streaming. */
    public function delete(Episode $episode): bool
    {
        // Prefer the recorded location; fall back to the old reconstructed path for episodes mirrored
        // before those columns existed.
        $disk = $episode->mirror_disk ?: 'public';
        $key = $episode->mirror_key;
        if (! $key) {
            $content = $episode->content;
            $key = ($content?->source && $content->source_key && $episode->number)
                ? "media/{$content->source}/{$content->source_key}/{$episode->number}.mp4"
                : null;
        }

        $removed = true;
        if ($key) {
            try {
                // Check existence first: on the local disk a delete of a missing path returns true, so
                // without this the caller cannot tell "removed" from "never found it" — and on remote
                // storage that difference is a file that keeps billing.
                $removed = Storage::disk($disk)->exists($key)
                    ? Storage::disk($disk)->delete($key)
                    : false;
            } catch (Throwable) {
                $removed = false;
            }
        }

        // The row is cleared either way — an episode must not keep pointing at a file we tried to
        // delete — but an orphan is left visible in the log rather than being silently forgotten.
        if (! $removed && $key) {
            logger()->warning('mirror: ลบไฟล์ไม่สำเร็จ อาจเหลือไฟล์ค้างในที่เก็บ', [
                'episode' => $episode->id, 'disk' => $disk, 'key' => $key,
            ]);
        }

        $episode->update([
            'video_url' => null, 'mirrored_at' => null, 'file_size' => null,
            'mirror_disk' => null, 'mirror_key' => null,
        ]);

        return $removed;
    }

    /**
     * Two separate limits that used to be conflated.
     *
     * The byte cap is the STORAGE budget and applies wherever the files go. The free-disk check is
     * about this machine only — it still matters on remote storage, because the download is written to
     * a local temp file in full before it is uploaded — but it is not a storage budget, and treating it
     * as one meant a remote run had no brake at all (the disk stays at ~190 GB free forever).
     */
    private function hasRoom(): bool
    {
        $usedBytes = (int) Episode::sum('file_size');
        $maxBytes = (float) config('services.ingest.max_gb', 100) * 1_000_000_000;
        if ($usedBytes >= $maxBytes) {
            return false;
        }

        $free = @disk_free_space(sys_get_temp_dir() ?: storage_path());

        return $free === false || $free >= 5_000_000_000;
    }
}
