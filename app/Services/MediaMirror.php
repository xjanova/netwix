<?php

namespace App\Services;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Admin-triggered "download this to our server" for progressive (MP4) sources — resolves the
 * episode's current signed URL, downloads it onto our own disk, and points the episode at the
 * stored file. After that playback serves our copy and never asks the source for a fresh link.
 * Also deletes a stored file again. HLS sources (anime108/wow-drama) aren't downloadable here
 * (they'd need ffmpeg to remux) — they keep streaming on demand.
 */
class MediaMirror
{
    private const MIN_BYTES = 10_000;
    private const MAX_BYTES = 700_000_000;   // 700MB hard cap per file
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Download one episode to our disk. @return array{ok:bool,bytes?:int,error?:string}
     */
    public function store(Episode $episode): array
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
        if (! $this->hasRoom()) {
            return ['ok' => false, 'error' => 'พื้นที่จัดเก็บเต็มเพดาน หรือดิสก์เซิร์ฟเวอร์ใกล้เต็ม'];
        }

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
            if ($size < self::MIN_BYTES || $size > self::MAX_BYTES) {
                return ['ok' => false, 'error' => 'ไฟล์ผิดปกติ ('.number_format($size / 1e6, 1).' MB)'];
            }

            $dir = "media/{$content->source}/{$content->source_key}";
            $name = $episode->number.'.mp4';
            Storage::disk('public')->putFileAs($dir, new File($tmp), $name);
            $path = "{$dir}/{$name}";

            $episode->update([
                'video_url' => Storage::disk('public')->url($path),
                'mirrored_at' => now(),
                'file_size' => (int) Storage::disk('public')->size($path),
                'mirror_trigger' => 'admin',
                'mirror_attempts' => 0,
                'mirror_failed_at' => null,
            ]);

            return ['ok' => true, 'bytes' => $size];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'ผิดพลาด: '.mb_substr($e->getMessage(), 0, 100)];
        } finally {
            @unlink($tmp);
        }
    }

    /** Delete a stored file and revert the episode to on-demand streaming. */
    public function delete(Episode $episode): bool
    {
        $content = $episode->content;
        if ($content?->source && $content->source_key && $episode->number) {
            Storage::disk('public')->delete("media/{$content->source}/{$content->source_key}/{$episode->number}.mp4");
        }
        $episode->update(['video_url' => null, 'mirrored_at' => null, 'file_size' => null]);

        return true;
    }

    /** Same storage guard the ingest endpoint uses. */
    private function hasRoom(): bool
    {
        $usedBytes = (int) Episode::sum('file_size');
        $maxBytes = (float) config('services.ingest.max_gb', 55) * 1_000_000_000;
        if ($usedBytes >= $maxBytes) {
            return false;
        }
        $free = @disk_free_space(storage_path());

        return $free === false || $free >= 5_000_000_000;
    }
}
