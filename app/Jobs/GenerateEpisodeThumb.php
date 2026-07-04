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
use Illuminate\Support\Facades\Process;

/**
 * Generate a per-episode cover by grabbing a frame from the playable source with
 * ffmpeg — server-side, so it works for BOTH the app and the web and for every
 * source (incl. cross-origin rongyok that the browser canvas can't read). First
 * capture wins: never overwrites an existing thumbnail.
 */
class GenerateEpisodeThumb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public int $episodeId) {}

    public function handle(SourceRegistry $registry): void
    {
        $episode = Episode::find($this->episodeId);
        if (! $episode || $episode->thumbnail_path) {
            return; // gone, or already has a cover (first-capture-wins)
        }

        $url = $this->playableUrl($episode, $registry);
        if (! $url) {
            return;
        }

        $tmp = sys_get_temp_dir().'/nwthumb_'.$episode->id.'_'.uniqid().'.jpg';
        $bin = config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');

        // Seek a few seconds in for a representative frame (before -i = fast seek),
        // scale to 640px wide keeping aspect (even height for the encoder).
        $result = Process::timeout($this->timeout)->run([
            $bin, '-y', '-ss', '3', '-i', $url,
            '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '4', $tmp,
        ]);

        try {
            if (! $result->successful() || ! is_file($tmp) || filesize($tmp) < 500) {
                return;
            }

            $data = file_get_contents($tmp);
            if ($data === false) {
                return;
            }

            $path = ImageStore::putWebp($data, 'media/thumbs', (string) $episode->id, 640);
            if ($path !== null && ! $episode->fresh()?->thumbnail_path) {
                $episode->update(['thumbnail_path' => $path]);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
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
