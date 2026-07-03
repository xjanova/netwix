<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads episode 1 of a source-backed title onto NetWix's own disk so it plays instantly and
 * can back the card hover-preview — WITHOUT the home downloader. Everything else (ep 2..N) still
 * resolves on demand at watch time. Idempotent: a title whose ep 1 already has a stored file is
 * skipped, so re-running is cheap.
 */
class PreviewDownloader
{
    private const MIN_BYTES = 10_000;          // reject error pages / truncated files
    private const MAX_BYTES = 200_000_000;     // a single short-drama ep is a few–tens of MB; cap at 200MB

    public function __construct(private SourceRegistry $registry) {}

    /** @return int|null bytes stored, or null if skipped / unavailable. */
    public function downloadFirstEpisode(Content $content): ?int
    {
        if (! $content->source || ! $content->source_key) {
            return null;
        }

        $ep = $content->episodes()->where('number', 1)->first();
        if (! $ep || $ep->video_url || ! $ep->source_ref) {
            return null;   // no ep 1, already stored, or nothing to resolve
        }

        if (! $this->hasRoom()) {
            return null;
        }

        $source = $this->registry->get($content->source);
        if (! $source) {
            return null;
        }

        $stream = $source->resolveByRef((string) $content->source_key, (string) $ep->source_ref);
        if (! $stream || $stream->kind !== RemoteStream::KIND_MP4 || $stream->url === '') {
            return null;   // source down / rotated again — a later run retries
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nxprev');
        try {
            $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->timeout(180)
                ->sink($tmp)
                ->get($stream->url);

            if (! $resp->ok()) {
                return null;
            }
            $size = (int) (@filesize($tmp) ?: 0);
            if ($size < self::MIN_BYTES || $size > self::MAX_BYTES) {
                return null;
            }

            $dir = "media/{$content->source}/{$content->source_key}";
            $path = "{$dir}/1.mp4";
            Storage::disk('public')->putFileAs($dir, new File($tmp), '1.mp4');

            $ep->update([
                'video_url' => Storage::disk('public')->url($path),
                'mirrored_at' => now(),
                'file_size' => (int) Storage::disk('public')->size($path),
                'mirror_trigger' => 'preview',
                'mirror_attempts' => 0,
                'mirror_failed_at' => null,
            ]);

            return $size;
        } finally {
            @unlink($tmp);
        }
    }

    /** Same storage guards the ingest endpoint uses — protect the shared server disk. */
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
