<?php

namespace App\Jobs;

use App\Models\FbEngagement;
use App\Support\FacebookMessenger;
use App\Support\FbInviteFunnel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Off the webhook request: map a recorded comment to the title it's about, decide (cooldown /
 * cap / kill-switch), and send the one-shot Private Reply invite. Runs on the light `fb-dm`
 * lane. Best-effort — a failure just marks the engagement, never crashes the worker.
 */
class SendFbInviteDm implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 40;
    public int $backoff = 30;

    public function __construct(public int $engagementId) {}

    public function handle(FbInviteFunnel $funnel, FacebookMessenger $fb): void
    {
        $eng = FbEngagement::find($this->engagementId);
        if (! $eng || $eng->dm_status !== 'pending') {
            return;
        }

        // Off entirely → record the intent but send nothing (so turning it on later is one toggle).
        if (! $funnel->enabled()) {
            $eng->update(['dm_status' => 'skipped', 'dm_error' => 'disabled']);

            return;
        }

        try {
            $content = $funnel->contentForPost($eng->fb_post_id, $fb);
            if (! $content) {
                $eng->update(['dm_status' => 'skipped', 'dm_error' => 'not_our_post']);

                return;
            }
            $eng->content()->associate($content);
            $eng->save();

            if (! $funnel->shouldInvite($eng->fb_user_id, $content->id)) {
                $eng->update(['dm_status' => 'skipped', 'dm_error' => 'cooldown_or_cap']);

                return;
            }
            if (blank($eng->comment_id)) {
                $eng->update(['dm_status' => 'skipped', 'dm_error' => 'no_comment_id']);

                return;
            }

            $message = $funnel->buildMessage($content, $eng->fb_user_id);
            $result = $fb->privateReply($eng->comment_id, $message);

            $eng->update($result['ok']
                ? ['dm_status' => 'sent', 'dm_error' => null]
                : ['dm_status' => 'failed', 'dm_error' => mb_substr((string) $result['error'], 0, 200)]);
        } catch (Throwable $e) {
            $eng->update(['dm_status' => 'failed', 'dm_error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
