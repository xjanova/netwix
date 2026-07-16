<?php

namespace App\Jobs;

use App\Models\ClipCampaignPost;
use App\Models\MarketingClip;
use App\Support\FacebookMessenger;
use App\Support\FacebookPublisher;
use Carbon\CarbonImmutable;
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
 *
 * MANUAL REPOST ($forceAfter set, from the admin "โพสต์/รีโพส" button): the same publish path,
 * but deliberately outside the campaign bookkeeping — no ClipCampaignPost row is touched, since
 * that row records a scheduled slot the admin is not re-running. See ClipController::repost.
 */
class PostClipToFacebook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 240;
    public int $backoff = 30;

    /**
     * @param  string|null  $forceAfter  ISO timestamp of a manual repost request. Lets this run
     *                                   publish a clip that already has an (older) posted_at,
     *                                   while STILL refusing to double-post within the same run —
     *                                   see alreadyPublished().
     */
    public function __construct(public int $clipId, public ?string $forceAfter = null) {}

    public function handle(FacebookPublisher $fb, FacebookMessenger $messenger): void
    {
        $clip = MarketingClip::find($this->clipId);
        if (! $clip) {
            return;
        }
        $post = $this->post($clip);

        if ($this->alreadyPublished($clip)) {
            return;
        }
        if (! $clip->is_ready) {
            $post?->markFailed('clip_not_ready');
            $this->noteError($clip, 'clip_not_ready');

            return;
        }

        $targets = array_values(array_filter(array_map('trim', explode(',', (string) $clip->post_targets))))
            ?: ['feed'];

        $result = $fb->postClip($clip, $targets);

        if ($result['dry_run']) {
            // Simulated — record it honestly, don't pretend it went live. On a manual repost the
            // admin was told we're connected, so a dry-run here means the page got disconnected
            // mid-flight: report it instead of silently re-flagging an already-live clip.
            if ($this->forceAfter !== null) {
                $this->noteError($clip, 'fb_not_connected');

                return;
            }
            $clip->update(['dry_run' => true]);
            $post?->update(['status' => 'posted', 'dry_run' => true, 'error' => null, 'targets_posted' => null]);

            return;
        }

        if (! empty($result['results'])) {
            $videoId = (string) reset($result['results']);
            $clip->update([
                'posted_at' => now(),
                'remote_post_id' => $videoId,
                // Resolve the feed STORY id now so a later comment on this post maps to the title
                // without a Graph lookup per comment (see FbInviteFunnel::contentForPost). Best-effort.
                'remote_story_id' => $messenger->resolveStoryId($videoId),
                'dry_run' => false,
                'meta' => $this->rememberPost($clip, $result['error']),
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
        $this->noteError($clip, $result['error'] ?? 'post_failed');
        throw new RuntimeException('facebook post failed: '.($result['error'] ?? 'unknown'));
    }

    public function failed(Throwable $e): void
    {
        $clip = MarketingClip::find($this->clipId);
        if ($clip && ! $this->alreadyPublished($clip)) {
            $this->post($clip)?->markFailed(mb_substr($e->getMessage(), 0, 250));
            $this->noteError($clip, mb_substr($e->getMessage(), 0, 250));
        }
    }

    /**
     * Has this clip already gone live for THIS run? Guards the retry (tries=3): a partial
     * success that then throws must not publish twice.
     *
     * Campaign post ($forceAfter null): any posted_at at all means done — original behaviour.
     * Manual repost: only a posted_at stamped *since the button was pressed* counts, so the
     * clip's older, historical posted_at can't block the repost the admin explicitly asked for.
     */
    private function alreadyPublished(MarketingClip $clip): bool
    {
        if (! $clip->posted_at) {
            return false;
        }
        if ($this->forceAfter === null) {
            return true;
        }

        return $clip->posted_at->gte(CarbonImmutable::parse($this->forceAfter));
    }

    /**
     * Campaign bookkeeping row — deliberately absent for a manual repost, whose publish is not a
     * scheduled slot. Without this, a repost would overwrite (or fail) the campaign's history.
     */
    private function post(MarketingClip $clip): ?ClipCampaignPost
    {
        if ($this->forceAfter !== null) {
            return null;
        }

        return ClipCampaignPost::where('marketing_clip_id', $clip->id)->latest('id')->first();
    }

    /**
     * Archive the ids of the post being superseded, so a comment on an EARLIER post still
     * resolves to this clip's title in the invite funnel — remote_post_id/remote_story_id only
     * ever hold the newest post (see FbInviteFunnel::contentForPost).
     *
     * @return array<string, mixed>  the clip's new meta
     */
    private function rememberPost(MarketingClip $clip, ?string $error): array
    {
        $meta = $clip->meta ?? [];

        foreach (['post_ids' => $clip->remote_post_id, 'story_ids' => $clip->remote_story_id] as $key => $old) {
            if ($old) {
                $meta[$key] = array_values(array_unique(array_merge($meta[$key] ?? [], [(string) $old])));
            }
        }
        // Manual publishes only. A campaign's partial-failure note belongs on its ClipCampaignPost
        // row, where it has always lived — mirroring it onto the clip would strand a permanent
        // "โพสต์ไม่สำเร็จ" next to "โพสต์แล้ว" on a card whose Reel went out fine.
        if ($this->forceAfter !== null) {
            if ($clip->posted_at) {
                $meta['repost_count'] = (int) ($meta['repost_count'] ?? 0) + 1;   // a re-post, not a first post
            }
            // Reached only on success, so any error here is PARTIAL (one surface took it, one didn't) —
            // flagged as such, because "โพสต์ไม่สำเร็จ" over a live Reel is a lie.
            $meta['last_post_error'] = $error;
            $meta['last_post_partial'] = $error !== null;
        }

        return $meta;
    }

    /** Surface a failure on the clip itself — a manual repost has no campaign log to read. */
    private function noteError(MarketingClip $clip, string $error): void
    {
        if ($this->forceAfter === null) {
            return;
        }
        $clip->update(['meta' => array_merge($clip->meta ?? [], [
            'last_post_error' => $error,
            'last_post_partial' => false,   // nothing went out at all — unlike rememberPost's note
        ])]);
    }
}
