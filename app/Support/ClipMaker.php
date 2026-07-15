<?php

namespace App\Support;

use App\Models\Episode;
use App\Models\MarketingClip;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cuts a short marketing clip out of a title/episode with ffmpeg — server-side, so it
 * works for every source (incl. cross-origin HLS the browser can't touch).
 *
 * Two hard constraints on the prod box (see brain: "NetWix server — ffmpeg …"):
 *   1. php-fpm has proc_open/exec DISABLED, so this MUST run from a CLI queue worker.
 *   2. the static ffmpeg SEGFAULTS on any https input, so we NEVER hand it a URL — we
 *      download the media locally first and run ffmpeg on the local file.
 *
 * To avoid pulling a whole movie just to cut 30s, HLS sources download only the handful
 * of .ts segments that cover [start, start+duration]; we concat their raw bytes (MPEG-TS
 * concatenates cleanly) and seek the small offset into the first segment. Output is a
 * FB-ready mp4 (vertical/square/landscape, +faststart) at media/clips/{id}.mp4, plus a
 * webp poster still.
 */
class ClipMaker
{
    /** Never pull more than this from a direct (non-HLS) mp4 source for a SHORT clip. */
    private const MP4_MAX_BYTES = 350 * 1024 * 1024;

    /** Source-download ceiling for a FULL-EPISODE cut (mp4 or summed HLS segments) — guards disk. */
    private const FULL_SRC_MAX_BYTES = 3 * 1024 * 1024 * 1024;

    /** Encoded output ceiling — Facebook's hosted file_url upload tops out around 1GB. */
    private const OUT_MAX_BYTES = 980 * 1024 * 1024;

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Produce + store the clip. Returns a status code:
     *   ok | no_source | download_failed | too_large | ffmpeg_failed | error
     * On ok the model is updated (status=ready, file_path, poster_path, file_size).
     */
    public function make(MarketingClip $clip): string
    {
        @ini_set('memory_limit', '1024M');   // a minute of 720p segments + encode headroom

        // Load the episode with ALL its columns (source/source_ref/video_url) — the job's
        // eager-load is column-limited for the label, which isn't enough to resolve a stream.
        $episode = $clip->episode_id
            ? Episode::with('content:id,source_key')->find($clip->episode_id)
            : Episode::with('content:id,source_key')->where('content_id', $clip->content_id)->orderBy('sort')->first();
        if (! $episode) {
            return $this->fail($clip, 'no_source');
        }

        $url = $this->playableUrl($episode);
        if (! $url) {
            return $this->fail($clip, 'no_source');
        }

        $start = max(0, (int) $clip->start);
        // duration <= 0 is the "ทั้งตอน" sentinel: no -t cut, every HLS segment, whole file.
        $full = (int) $clip->duration <= 0;
        $dur = $full ? 0 : max(5, min(600, (int) $clip->duration));

        // ---- 1. get the source window onto local disk --------------------------
        [$srcPath, $seekOffset] = $this->fetchWindow($url, $start, $dur, $full);
        if ($srcPath === null) {
            // transient CDN hiccup on a burst — re-resolve a fresh link + retry once
            usleep(700_000);
            $retry = $this->playableUrl($episode);
            [$srcPath, $seekOffset] = $retry ? $this->fetchWindow($retry, $start, $dur, $full) : [null, 0];
            if ($srcPath === null) {
                return $this->fail($clip, 'download_failed');
            }
        }

        $out = $srcPath.'.mp4';
        try {
            // ---- 2. cut + re-encode to a FB-ready file -------------------------
            if (! $this->encode($srcPath, $out, $seekOffset, $dur, (string) $clip->aspect, $full)) {
                return $this->fail($clip, 'ffmpeg_failed');
            }
            $size = is_file($out) ? (int) filesize($out) : 0;
            if ($size < 2000) {
                return $this->fail($clip, 'ffmpeg_failed');
            }
            if ($size > self::OUT_MAX_BYTES) {
                return $this->fail($clip, 'too_large');   // FB can't fetch a hosted file this big
            }

            // Stream the file into storage — a full episode is far too big to slurp into RAM.
            $path = 'media/clips/'.$clip->id.'.mp4';
            $put = Storage::disk('public')->putFileAs('media/clips', new \Illuminate\Http\File($out), $clip->id.'.mp4');
            if ($put === false) {
                return $this->fail($clip, 'error');
            }

            // ---- 3. a poster still (local mp4 input — always safe for ffmpeg) --
            $poster = $this->poster($out, $clip->id);

            $clip->update([
                'status' => 'ready',
                'error' => null,
                'file_path' => $path,
                'poster_path' => $poster,
                'file_size' => $size,
            ]);

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return $this->fail($clip, 'error');
        } finally {
            @unlink($srcPath);
            @unlink($out);
        }
    }

    // ---- source window ------------------------------------------------------

    /**
     * Download the media covering [start, start+dur] to a local temp file.
     * Returns [localPath, seekOffsetSeconds] — seekOffset is how far into that local
     * file the clip actually starts (0 for a whole-file mp4; the fraction of the first
     * segment for HLS). Returns [null, 0] on failure.
     *
     * @return array{0: ?string, 1: float}
     */
    private function fetchWindow(string $url, int $start, int $dur, bool $full = false): array
    {
        try {
            if (str_contains($url, '.m3u8')) {
                return $this->fetchHlsWindow($url, $start, $dur, $full);
            }

            // Direct mp4/file: no cheap way to time-seek a remote file, and ffmpeg
            // can't take the https URL — so pull the whole thing (bounded) and let
            // ffmpeg seek locally. Fine for our own mirrored files; guarded for size.
            $head = Http::timeout(20)->withOptions(['stream' => true])->get($url);
            $len = (int) $head->header('Content-Length');
            if ($len > ($full ? self::FULL_SRC_MAX_BYTES : self::MP4_MAX_BYTES)) {
                return [null, 0.0];  // caller maps a null to download_failed; logged size guard
            }
            $tmp = $this->tmp();
            // sink() streams straight to disk — the file never sits in PHP memory.
            $resp = Http::timeout($full ? 900 : 180)->sink($tmp)->get($url);
            if (! $resp->successful() || ! is_file($tmp) || filesize($tmp) < 2000) {
                @unlink($tmp);

                return [null, 0.0];
            }

            return [$tmp, (float) $start];   // whole file downloaded → seek the real start
        } catch (Throwable $e) {
            report($e);

            return [null, 0.0];
        }
    }

    /**
     * HLS: parse the media playlist, pick the segment run covering the window, download
     * + strip + concat just those segments. Returns [localTsPath, offsetIntoFirstSeg].
     *
     * @return array{0: ?string, 1: float}
     */
    private function fetchHlsWindow(string $m3u8Url, int $start, int $dur, bool $full = false): array
    {
        $segments = $this->mediaSegments($m3u8Url);
        if (empty($segments)) {
            return [null, 0.0];
        }

        if ($full) {
            // Whole episode: every segment, from the top.
            $picked = array_column($segments, 'url');
            $firstOffset = 0.0;
        } else {
            // Walk cumulative time to find the run of segments overlapping [start, start+dur].
            $tail = 1.5;                        // grab a hair extra so -t never runs short
            $need = $start + $dur + $tail;
            $cursor = 0.0;
            $firstOffset = 0.0;
            $picked = [];
            foreach ($segments as $seg) {
                $segStart = $cursor;
                $segEnd = $cursor + $seg['dur'];
                $cursor = $segEnd;

                if ($segEnd <= $start) {
                    continue;                   // entirely before the window
                }
                if (empty($picked)) {
                    $firstOffset = max(0.0, $start - $segStart);   // where the clip starts inside seg #0
                }
                $picked[] = $seg['url'];
                if ($segStart >= $need) {
                    break;                      // covered the whole window
                }
            }
            if (empty($picked)) {
                // start past the end of the video — fall back to the first segments
                $picked = array_slice(array_column($segments, 'url'), 0, 3);
                $firstOffset = 0.0;
            }
        }

        $tmp = $this->tmp();
        $fh = @fopen($tmp, 'wb');
        if (! $fh) {
            return [null, 0.0];
        }
        $got = 0;
        $written = 0;
        foreach ($picked as $segUrl) {
            $data = $this->fetchSegment($segUrl);
            if ($data !== null) {
                fwrite($fh, $data);
                $written += strlen($data);
                $got++;
            }
            if ($written > self::FULL_SRC_MAX_BYTES) {
                fclose($fh);
                @unlink($tmp);

                return [null, 0.0];             // source bigger than the disk guard allows
            }
        }
        fclose($fh);

        if ($got === 0 || filesize($tmp) < 2000) {
            @unlink($tmp);

            return [null, 0.0];
        }

        return [$tmp, $firstOffset];
    }

    /**
     * Fetch + parse an HLS playlist into [{url, dur}]. Follows one level of a master
     * playlist (variant list) down to the media playlist.
     *
     * @return array<int, array{url: string, dur: float}>
     */
    private function mediaSegments(string $m3u8Url, int $depth = 0): array
    {
        try {
            $body = Http::timeout(25)->get($m3u8Url)->body();
        } catch (Throwable $e) {
            return [];
        }
        if ($body === '' || ! str_contains($body, '#EXT')) {
            return [];
        }

        // Master playlist → recurse into the first variant.
        if (str_contains($body, '#EXT-X-STREAM-INF') && $depth < 2) {
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                return $this->mediaSegments($this->absolute($line, $m3u8Url), $depth + 1);
            }

            return [];
        }

        $segments = [];
        $dur = 0.0;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                $dur = (float) trim(substr($line, 8), " ,");
                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            $segments[] = ['url' => $this->absolute($line, $m3u8Url), 'dur' => $dur > 0 ? $dur : 6.0];
            $dur = 0.0;
        }

        return $segments;
    }

    /** Download one segment, stripping any fake-image header disguise (to first 0x47). */
    private function fetchSegment(string $url): ?string
    {
        try {
            $resp = Http::timeout(60)->get($url);
            if (! $resp->successful()) {
                return null;
            }
            $data = $resp->body();
            if (strlen($data) < 200) {
                return null;
            }
            // Some CDNs (and our own anti-hotlink proxy) prepend a fake image header;
            // the real MPEG-TS starts at the first sync byte 0x47.
            $pos = strpos($data, "\x47");
            if ($pos !== false && $pos > 0 && $pos < 512) {
                $data = substr($data, $pos);
            }

            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---- encode -------------------------------------------------------------

    /** Cut [offset, offset+dur] from the local source and re-encode FB-ready. */
    private function encode(string $src, string $out, float $offset, int $dur, string $aspect, bool $full = false): bool
    {
        @unlink($out);
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        $args = [
            $bin, '-y', '-nostdin', '-threads', '4',
            '-ss', number_format($offset, 3, '.', ''),
            '-i', $src,
        ];
        if (! $full) {
            array_push($args, '-t', (string) $dur);   // full episode = no cut, encode to the end
        }
        array_push($args,
            '-vf', $this->videoFilter($aspect, $full),
            '-c:v', 'libx264', '-preset', $full ? 'superfast' : 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ar', '44100',
            '-movflags', '+faststart',
            '-avoid_negative_ts', 'make_zero',
            $out,
        );
        try {
            // A whole episode legitimately encodes for a long time (still nice/ionice'd + 4
            // threads, so it only ever uses idle CPU — see the 2026-07-06 incident note).
            Process::timeout($full ? 5100 : 240)->run(Ffmpeg::cmd($args));
        } catch (Throwable $e) {
            return false;
        }

        return is_file($out) && filesize($out) >= 2000;
    }

    /**
     * Build the -vf chain: cover-and-crop to the target aspect (fills the frame, no
     * letterbox), then an optional burned-in CTA. The CTA is only added when a usable
     * font is configured (services.ffmpeg.font) — otherwise drawtext would abort the
     * encode on a box with no fonts, so we degrade gracefully to no overlay.
     *
     * Full episodes are NOT cropped — cutting a whole episode to 9:16 would throw away
     * most of the frame for 45 minutes. They keep the source aspect, just bounded to
     * 1280 wide (never upscaled) to keep the output under Facebook's hosted-file limit.
     */
    private function videoFilter(string $aspect, bool $full = false): string
    {
        [$w, $h] = match ($aspect) {
            '1:1' => [1080, 1080],
            '16:9' => [1280, 720],
            default => [720, 1280],   // 9:16 vertical (Reels/TikTok)
        };
        $chain = $full
            ? "scale=w='min(1280,iw)':h=-2"
            : "scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h}";

        $font = (string) config('services.ffmpeg.font', '');
        $cta = trim((string) config('services.ffmpeg.clip_cta', 'ดูเต็มเรื่องฟรี · แอป NetWix'));
        if ($font !== '' && is_file($font) && $cta !== '') {
            $text = str_replace(["\\", ':', "'", '%'], ['\\\\', '\\:', "\u{2019}", '\\%'], $cta);
            $fontEsc = str_replace(['\\', ':'], ['/', '\\:'], $font);
            $chain .= ",drawtext=fontfile='{$fontEsc}':text='{$text}':fontcolor=white:fontsize=44"
                .':box=1:boxcolor=black@0.55:boxborderw=16:x=(w-text_w)/2:y=h-th-70';
        }

        return $chain;
    }

    /** Grab a still from the finished LOCAL mp4 for the admin thumbnail. */
    private function poster(string $localMp4, int $clipId): ?string
    {
        $jpg = $localMp4.'.poster.jpg';
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        try {
            Process::timeout(30)->run(Ffmpeg::cmd([
                $bin, '-y', '-nostdin', '-ss', '1', '-i', $localMp4,
                '-frames:v', '1', '-q:v', '4', $jpg,
            ]));
            $data = @file_get_contents($jpg);
            if ($data !== false && strlen($data) > 400) {
                return ImageStore::putWebp($data, 'media/clips', 'poster-'.$clipId, 640);
            }
        } catch (Throwable $e) {
            // poster is a nicety — never fail the clip over it
        } finally {
            @unlink($jpg);
        }

        return null;
    }

    // ---- helpers ------------------------------------------------------------

    private function fail(MarketingClip $clip, string $status): string
    {
        $clip->update(['status' => 'failed', 'error' => $status]);

        return $status;
    }

    private function tmp(): string
    {
        return sys_get_temp_dir().'/nwclip_'.bin2hex(random_bytes(6));
    }

    /** Resolve a relative playlist/segment line against its playlist URL. */
    private function absolute(string $ref, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $ref)) {
            return $ref;
        }
        $p = parse_url($baseUrl);
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? ':'.$p['port'] : '';
        if (str_starts_with($ref, '/')) {
            return "{$scheme}://{$host}{$port}{$ref}";
        }
        $dir = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/');

        return "{$scheme}://{$host}{$port}{$dir}{$ref}";
    }

    /**
     * A directly-fetchable URL for an episode's video. Mirrors
     * EpisodeThumbnailer::playableUrl (kept local so the proven cover path stays
     * untouched): prefer a stored video_url, else resolve the upstream source link.
     */
    private function playableUrl(\App\Models\Episode $episode): ?string
    {
        if ($episode->video_url) {
            return $episode->video_url;
        }
        if (in_array($episode->source, ['wowdrama', 'anime108'], true)) {
            return route('stream.manifest', $episode);
        }
        $source = $this->registry->get((string) $episode->source);
        $seriesKey = $episode->content?->source_key;
        if (! $source || ! $seriesKey || ! $episode->source_ref) {
            return null;
        }

        return $source->resolveByRef((string) $seriesKey, (string) $episode->source_ref)?->url;
    }
}
