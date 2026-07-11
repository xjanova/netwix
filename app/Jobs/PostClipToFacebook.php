<?php

namespace App\Jobs;

use App\Models\ClipCampaignPost;
use App\Models\MarketingClip;
use App\Support\FacebookPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Publishes a freshly-cut campaign clip to the NetWix Facebook page, then reconciles the
 * clip + its campaign-post row. Runs on the light `clips-post` lane — it's an HTTP upload
 * (FB pulls the hosted file), never ffmpeg, so it's safe to share a worker.
 *
 * Dry-run (Facebook not connected): nothing is sent. The clip stays a normal "ready" clip
 * (posted_at left null, so the "โพสต์ไปแล้ว" counter never lies) and the campaign-post is
 * marked posted+dry_run so the slot won't re-fire and the admin log shows the honest state.
 */
class PostClipToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 240;
    public int $backoff = 30;

    public function __construct(public int $clipId) {}

    public function handle(FacebookPublisher $fb): void
    {
        $clip = MarketingClip::find($this->clipId);
        if (! $clip) {
            return;
        }
        $post = $this->post($clip);

        // Idempotent: a retry after a partial success must not double-post.
        if ($clip->posted_at) {
            return;
        }
        if (! $clip->is_ready) {
            $post?->markFailed('clip_not_ready');

            return;
        }

        $targets = array_values(array_filter(array_map('trim', explode(',', (string) $clip->post_targets))))
            ?: ['feed'];

        $result = $fb->postClip($clip, $targets);

        if ($result['dry_run']) {
            // Simulated — record it honestly, don't pretend it went live.
            $clip->update(['dry_run' => true]);
            $post?->update(['status' => 'posted', 'dry_run' => true, 'error' => null, 'targets_posted' => null]);

            return;
        }

        if (! empty($result['results'])) {
            $clip->update([
                'posted_at' => now(),
                'remote_post_id' => (string) reset($result['results']),
                'dry_run' => false,
            ]);
            $post?->update([
                'status' => 'posted',
                'dry_run' => false,
                'targets_posted' => $result['results'],
                'error' => $result['error'], // may hold a partial-failure note (one surface ok, one not)
            ]);

            return;
        }

        // Total failure — surface it so the job retries, and flag the post row.
        $post?->markFailed($result['error'] ?? 'post_failed');
        throw new RuntimeException('facebook post failed: '.($result['error'] ?? 'unknown'));
    }

    public function failed(Throwable $e): void
    {
        $clip = MarketingClip::find($this->clipId);
        if ($clip && ! $clip->posted_at) {
            $this->post($clip)?->markFailed(mb_substr($e->getMessage(), 0, 250));
        }
    }

    private function post(MarketingClip $clip): ?ClipCampaignPost
    {
        return ClipCampaignPost::where('marketing_clip_id', $clip->id)->latest('id')->first();
    }
}
