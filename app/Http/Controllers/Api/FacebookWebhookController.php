<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendFbInviteDm;
use App\Models\FbEngagement;
use App\Models\Setting;
use App\Support\FacebookMessenger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Facebook Page webhook for the comment→invite-DM funnel.
 *   GET  /api/fb/webhook — the subscription handshake (echo hub.challenge).
 *   POST /api/fb/webhook — feed events; a new comment on one of our clip posts records an
 *                          engagement + queues a title-specific invite (see [SendFbInviteDm]).
 * Public + CSRF-exempt (api routes are), but POSTs are authenticated by the app-secret signature.
 */
class FacebookWebhookController extends Controller
{
    /** Subscription verification: Facebook GETs with the token we set; echo the challenge. */
    public function verify(Request $request, FacebookMessenger $fb): Response
    {
        $token = $fb->webhookVerifyToken();
        if ($request->query('hub_mode') === 'subscribe'
            && $token !== ''
            && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('forbidden', 403);
    }

    /**
     * Feed events. Always answer 200 fast (Facebook retries otherwise) — the heavy lifting is a
     * queued job. Reject anything not signed by our app secret.
     */
    public function receive(Request $request, FacebookMessenger $fb): Response
    {
        if (! $fb->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response('bad signature', 403);
        }

        $ourPage = (string) (Setting::get('fb_page_id') ?: config('services.facebook.page_id'));

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'feed') {
                    continue;
                }
                $v = $change['value'] ?? [];
                // Only brand-new top-level/any comments; not our own page's replies, not edits/removes.
                if (($v['item'] ?? '') !== 'comment' || ($v['verb'] ?? '') !== 'add') {
                    continue;
                }
                $fromId = (string) ($v['from']['id'] ?? '');
                $postId = (string) ($v['post_id'] ?? '');
                $commentId = (string) ($v['comment_id'] ?? '');
                if ($fromId === '' || $fromId === $ourPage || $postId === '' || $commentId === '') {
                    continue;
                }

                // Record every commenter (audit) even if we later skip the DM; the job decides.
                $eng = FbEngagement::create([
                    'fb_user_id' => $fromId,
                    'fb_user_name' => $v['from']['name'] ?? null,
                    'fb_post_id' => $postId,
                    'comment_id' => $commentId,
                    'kind' => 'comment',
                    'dm_status' => 'pending',
                ]);
                SendFbInviteDm::dispatch($eng->id)->onQueue('fb-dm');
            }
        }

        return response('ok', 200);
    }
}
