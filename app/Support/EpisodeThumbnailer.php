<?php

namespace App\Support;

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

        $src = $this->downloadToTemp($url);
        if ($src === null) {
            return 'download_failed';
        }

        $out = $src.'.jpg';
        try {
            if (! $this->grab($src, $out, '3') && ! $this->grab($src, $out, '0')) {
                return 'ffmpeg_failed';
            }

            $data = @file_get_contents($out);
            if ($data === false || strlen($data) < 400) {
                return 'ffmpeg_failed';
            }

            $path = ImageStore::putWebp($data, 'media/thumbs', (string) $episode->id, 640);
            if ($path === null) {
                return 'ffmpeg_failed';
            }

            // Same filename is reused, so store a cache-busted URL to force
            // Cloudflare/clients to refetch when regenerating.
            $episode->update(['thumbnail_path' => Storage::disk('public')->url($path).'?t='.now()->timestamp]);

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'error';
        } finally {
            @unlink($src);
            @unlink($out);
        }
    }

    private function grab(string $src, string $out, string $seek): bool
    {
        @unlink($out);
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        try {
            Process::timeout(60)->run([
                $bin, '-y', '-nostdin', '-ss', $seek, '-i', $src,
                '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', $out,
            ]);
        } catch (Throwable $e) {
            return false;
        }

        return is_file($out) && filesize($out) >= 400;
    }

    private function downloadToTemp(string $url): ?string
    {
        try {
            if (str_contains($url, '.m3u8')) {
                $seg = $this->firstSegment($url);
                if ($seg === null) {
                    return null;
                }
                $url = $seg;
            }

            $resp = Http::timeout(90)->get($url);
            if (! $resp->successful()) {
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

    private function firstSegment(string $m3u8Url): ?string
    {
        $body = Http::timeout(20)->get($m3u8Url)->body();
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('~^https?://~', $line)) {
                return $line;
            }
            if (str_starts_with($line, '/')) {
                $p = parse_url($m3u8Url);

                return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').$line;
            }

            return preg_replace('~/[^/]*$~', '/', $m3u8Url).$line;
        }

        return null;
    }

    private function playableUrl(Episode $episode): ?string
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
