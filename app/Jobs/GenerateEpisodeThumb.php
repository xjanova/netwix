<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use App\Support\ImageStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Generate a per-episode cover with ffmpeg — server-side, so it works for BOTH
 * the app and the web and for every source (incl. cross-origin rongyok that the
 * browser canvas can't read). First capture wins.
 *
 * The static ffmpeg on the box SEGFAULTS on https input, so we download the media
 * with PHP/Guzzle first (mp4 whole; HLS = its first segment) and run ffmpeg on
 * the LOCAL file.
 */
class GenerateEpisodeThumb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $episodeId) {}

    public function handle(SourceRegistry $registry): void
    {
        $episode = Episode::find($this->episodeId);
        if (! $episode || $episode->thumbnail_path) {
            return;
        }

        $url = $this->playableUrl($episode, $registry);
        if (! $url) {
            return;
        }

        $src = $this->downloadToTemp($url);
        if ($src === null) {
            return;
        }

        $out = $src.'.jpg';
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
        try {
            // Seek 3s in for a representative frame; if that yields nothing (very
            // short first segment) fall back to the first frame.
            if (! $this->grab($bin, $src, $out, '3') && ! $this->grab($bin, $src, $out, '0')) {
                return;
            }

            $data = @file_get_contents($out);
            if ($data === false || strlen($data) < 400) {
                return;
            }

            $path = ImageStore::putWebp($data, 'media/thumbs', (string) $episode->id, 640);
            if ($path !== null && ! $episode->fresh()?->thumbnail_path) {
                $episode->update(['thumbnail_path' => $path]);
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            @unlink($src);
            @unlink($out);
        }
    }

    /** Run ffmpeg on the LOCAL file (never segfaults) → one scaled JPEG frame. */
    private function grab(string $bin, string $src, string $out, string $seek): bool
    {
        @unlink($out);
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

    /** Download the media to a temp file (HLS → its first segment). */
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

    /** The first media segment URL from an HLS playlist (absolute or resolved). */
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
            // Relative segment → resolve against the playlist URL.
            if (str_starts_with($line, '/')) {
                $p = parse_url($m3u8Url);

                return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').$line;
            }

            return preg_replace('~/[^/]*$~', '/', $m3u8Url).$line;
        }

        return null;
    }

    /** Resolve the current playable URL, mirroring EpisodeSourceController. */
    private function playableUrl(Episode $episode, SourceRegistry $registry): ?string
    {
        if ($episode->video_url) {
            return $episode->video_url;
        }
        if (in_array($episode->source, ['wowdrama', 'anime108'], true)) {
            return route('stream.manifest', $episode);
        }
        $source = $registry->get((string) $episode->source);
        $seriesKey = $episode->content?->source_key;
        if (! $source || ! $seriesKey || ! $episode->source_ref) {
            return null;
        }

        return $source->resolveByRef((string) $seriesKey, (string) $episode->source_ref)?->url;
    }
}
