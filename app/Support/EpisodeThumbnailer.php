<?php

namespace App\Support;

use App\Http\Controllers\StreamController;
use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates a per-episode cover by grabbing a frame with ffmpeg — server-side,
 * works for every source (incl. cross-origin rongyok the browser can't read).
 *
 * The static ffmpeg on the box SEGFAULTS on https input, so we download the media
 * with PHP/Guzzle first (mp4 whole; HLS = its first segment) and run ffmpeg on
 * the LOCAL file. Output is a 640px WebP stored at media/thumbs/{id}.webp.
 */
class EpisodeThumbnailer
{
    /**
     * Progressive-mp4 sources (rongyok, anifume) are faststart (moov up front), so ffmpeg can seek
     * inside just the opening chunk — no need to pull a whole ~130MB anifume episode for one frame.
     * Cap the download to this many bytes (~28MB ≈ the first ~20-40s of video) to slash ingress.
     */
    private const PROGRESSIVE_CAP_BYTES = 28_000_000;

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Generate + store the cover. Returns a status code so the UI can explain
     * failures: ok | exists | no_source | download_failed | ffmpeg_failed | error.
     * (`no_source` = the upstream video link is gone/expired — nothing to grab.)
     * When [$force] is false an episode that already has a cover is left alone.
     */
    public function generate(Episode $episode, bool $force = false): string
    {
        if ($episode->thumbnail_path && ! $force) {
            return 'exists';
        }

        $url = $this->playableUrl($episode);
        if (! $url) {
            return 'no_source';
        }

        // Whole file for HLS (segment) / manual urls; a capped range for progressive mp4 (faststart).
        $cap = str_contains($url, '.m3u8') ? null : self::PROGRESSIVE_CAP_BYTES;
        $src = $this->downloadToTemp($url, $cap);
        if ($src === null) {
            // Transient (CDN throttle / hiccup on a burst) — re-resolve a FRESH
            // signed link and try once more before giving up.
            usleep(700_000);
            $retry = $this->playableUrl($episode);
            $src = $retry ? $this->downloadToTemp($retry, $cap) : null;
            if ($src === null) {
                return 'download_failed';
            }
        }

        // If the cap actually truncated the video (file filled to the cap), the deep proportional
        // seeks in grab() would land past the bytes we have → tell it to grab from the opening window.
        $partial = $cap !== null && (int) @filesize($src) >= $cap;

        $out = $src.'.jpg';
        try {
            if (! $this->grab($src, $out, $partial)) {
                return 'ffmpeg_failed';
            }

            $data = @file_get_contents($out);
            if ($data === false || strlen($data) < 400) {
                return 'ffmpeg_failed';
            }

            // Unique filename per run (see ImageStore::putCover) so a regenerated cover isn't served
            // stale by Cloudflare/the browser; the old file is cleaned up. Store the relative path —
            // getThumbnailUrlAttribute resolves it to a URL.
            $path = ImageStore::putCover($data, 'media/thumbs', (string) $episode->id, $episode->thumbnail_path, 640);
            if ($path === null) {
                return 'ffmpeg_failed';
            }

            $episode->update(['thumbnail_path' => $path]);

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'error';
        } finally {
            @unlink($src);
            @unlink($out);
        }
    }

    /**
     * Grab a REPRESENTATIVE, non-black cover frame. Seeking a fixed 3s (the old behaviour) almost
     * always landed on the intro/logo/black fade — the reason covers came out black. Instead: seek
     * PROPORTIONALLY past the intro, let ffmpeg's `thumbnail` filter pick the least-uniform frame in a
     * window (it naturally skips flat/black frames), then reject near-black results with a GD luminance
     * check — trying a few points and keeping the brightest as a last resort. `-threads 2` caps CPU.
     */
    private function grab(string $src, string $out, bool $partial = false): bool
    {
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        $dur = $this->duration($src);
        // Sample points: skip the intro, avoid credits. Proportional when the duration is known;
        // a short HLS segment / unknown duration falls back to small fixed offsets. For a PARTIAL
        // (range-capped) progressive file only the opening ~20-40s is on disk, so seek early — the
        // moov header still reports the FULL duration, so proportional seeks would miss the data.
        $seeks = $partial
            ? [16, 10, 5, 20]
            : ($dur > 25
                ? [(int) ($dur * 0.30), (int) ($dur * 0.50), (int) ($dur * 0.15), (int) ($dur * 0.70)]
                : ($dur > 6 ? [(int) ($dur * 0.5), 3, 1] : [2, 1, 0]));

        $bestData = null;
        $bestLuma = -1.0;
        foreach (array_values(array_unique($seeks)) as $ss) {
            @unlink($out);
            try {
                Process::timeout(60)->run([
                    $bin, '-y', '-nostdin', '-threads', '2', '-ss', (string) $ss, '-i', $src,
                    '-frames:v', '1', '-vf', 'thumbnail=n=40,scale=640:-2', '-q:v', '4', $out,
                ]);
            } catch (Throwable $e) {
                continue;
            }
            if (! is_file($out) || filesize($out) < 400) {
                continue;
            }
            $luma = $this->avgLuma($out);
            if ($luma >= 26.0) {
                return true;                 // clearly not black → good cover, stop early
            }
            if ($luma > $bestLuma) {         // remember the least-dark frame as a fallback
                $bestLuma = $luma;
                $bestData = @file_get_contents($out);
            }
        }

        // Every sample came back dark (rare) — keep the brightest rather than nothing.
        if ($bestData !== null && strlen($bestData) >= 400) {
            file_put_contents($out, $bestData);

            return true;
        }

        return false;
    }

    /** Media duration in seconds via a cheap ffmpeg header probe (no ffprobe dependency). 0 = unknown. */
    private function duration(string $src): float
    {
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        try {
            $r = Process::timeout(20)->run([$bin, '-hide_banner', '-i', $src]);
            $txt = $r->errorOutput()."\n".$r->output();
            if (preg_match('/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $txt, $m)) {
                return (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3];
            }
        } catch (Throwable $e) {
            // unknown → caller falls back to fixed offsets
        }

        return 0.0;
    }

    /** Mean perceptual luminance (0-255) over a sparse grid — used to reject near-black frames. */
    private function avgLuma(string $jpeg): float
    {
        $img = @imagecreatefromjpeg($jpeg);
        if (! $img) {
            return 999.0;   // unreadable → don't reject on brightness
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $stepX = max(1, (int) ($w / 20));
        $stepY = max(1, (int) ($h / 20));
        $sum = 0.0;
        $n = 0;
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($img, $x, $y);
                $sum += 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $n++;
            }
        }
        imagedestroy($img);

        return $n > 0 ? $sum / $n : 999.0;
    }

    private function downloadToTemp(string $url, ?int $capBytes = null): ?string
    {
        try {
            if (str_contains($url, '.m3u8')) {
                $seg = $this->pickSegment($url);
                if ($seg === null) {
                    return null;
                }
                $url = $seg;
                $capBytes = null;   // a single HLS segment is already small — take it whole
            }

            $req = Http::timeout(90);
            if ($capBytes !== null) {
                $req = $req->withHeaders(['Range' => 'bytes=0-'.$capBytes]);   // 206 for a faststart mp4
            }
            $resp = $req->get($url);
            if (! $resp->successful()) {   // 2xx incl. 206
                return null;
            }
            $body = $resp->body();
            if (strlen($body) < 1000) {
                return null;
            }

            $tmp = sys_get_temp_dir().'/nwsrc_'.uniqid();
            file_put_contents($tmp, $body);

            return $tmp;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Pick a media segment ~a third of the way into the playlist — NOT the first one. The opening
     * segments are almost always an intro/logo/black fade (exactly why first-segment grabs came out
     * black); sampling mid-playlist lands on real content. Falls back safely for tiny playlists.
     */
    private function pickSegment(string $m3u8Url, float $frac = 0.35): ?string
    {
        $body = Http::timeout(20)->get($m3u8Url)->body();
        $segs = [];
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $segs[] = $this->absUrl($line, $m3u8Url);
        }
        if ($segs === []) {
            return null;
        }
        $idx = max(0, min((int) floor(count($segs) * $frac), count($segs) - 1));

        return $segs[$idx];
    }

    /** Resolve a playlist line (absolute, root-relative, or relative) against the manifest URL. */
    private function absUrl(string $line, string $base): string
    {
        if (preg_match('~^https?://~', $line)) {
            return $line;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($line, '/')) {
            return $origin.$line;
        }

        return preg_replace('~/[^/]*(\?.*)?$~', '/', $base).$line;
    }

    private function playableUrl(Episode $episode): ?string
    {
        if ($episode->video_url) {
            return $episode->video_url;
        }
        if (in_array($episode->source, ['wowdrama', 'anime108'], true)) {
            // Our proxy manifest is token-gated — mint a token so this server-side fetch isn't 403'd.
            return route('stream.manifest', $episode).'?t='.StreamController::token($episode);
        }
        $source = $this->registry->get((string) $episode->source);
        $seriesKey = $episode->content?->source_key;
        if (! $source || ! $seriesKey || ! $episode->source_ref) {
            return null;
        }

        return $source->resolveByRef((string) $seriesKey, (string) $episode->source_ref)?->url;
    }
}
