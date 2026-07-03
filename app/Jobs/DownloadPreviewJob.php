<?php

namespace App\Jobs;

use App\Models\Content;
use App\Services\PreviewDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Downloads & stores episode 1 of a title on demand — queued the first time a
 * viewer opens a title whose ep1 isn't mirrored yet, so it plays instantly next
 * time and backs the browse hover-preview. Idempotent (PreviewDownloader skips a
 * title whose ep1 already has a stored file); complements the netwix:previews cron.
 */
class DownloadPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 200;

    public int $tries = 1;

    public function __construct(public int $contentId) {}

    public function handle(PreviewDownloader $downloader): void
    {
        if ($content = Content::find($this->contentId)) {
            $downloader->downloadFirstEpisode($content);
        }
    }

    /**
     * Queue an ep1 download when a title is opened, but at most once per title
     * per hour — the modal and the full title page share this, and repeat views
     * shouldn't pile up duplicate jobs.
     */
    public static function maybeDispatch(Content $content): void
    {
        if (! $content->source || ! $content->source_key) {
            return;
        }

        $ep = $content->relationLoaded('episodes')
            ? $content->episodes->firstWhere('number', 1)
            : $content->episodes()->where('number', 1)->first();

        // Nothing to do if there's no ep1, it's already stored, or it has no ref to resolve.
        if (! $ep || $ep->video_url || ! $ep->source_ref) {
            return;
        }

        if (Cache::add("preview:dispatch:{$content->id}", 1, now()->addHour())) {
            self::dispatch($content->id);
        }
    }
}
