<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The messaging/read half of our Facebook integration (FacebookPublisher owns publishing).
 * Used by the comment→DM invite funnel: verify webhook payloads, send a one-shot Private Reply
 * to a comment, and resolve the post↔title mapping via the Graph API.
 *
 * Every call is best-effort and returns a structured result — a funnel step never throws into
 * the webhook/queue. Sending needs the `pages_messaging` permission (App Review); until that is
 * granted the private-reply call returns an error result and the funnel records it as failed.
 */
class FacebookMessenger
{
    /** True once a page id + token are present (same connection FacebookPublisher uses). */
    public function connected(): bool
    {
        return filled($this->token()) && filled($this->page());
    }

    /**
     * Verify a webhook POST came from Facebook: HMAC-SHA256 of the raw body keyed by the app
     * secret must equal the `X-Hub-Signature-256` header. No secret configured → cannot verify,
     * so we refuse (fail closed) rather than trust an unsigned payload.
     */
    public function verifySignature(string $rawBody, ?string $header): bool
    {
        $secret = (string) Setting::get('fb_app_secret', (string) config('services.facebook.app_secret', ''));
        if ($secret === '' || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }

    /** The token an incoming GET webhook-verification handshake must echo (admin-set). */
    public function webhookVerifyToken(): string
    {
        return (string) Setting::get('fb_webhook_verify_token', '');
    }

    /**
     * Send ONE private reply to a comment — the only policy-compliant way to DM someone who
     * merely commented (Facebook allows a single private message per comment, within 7 days).
     *
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function privateReply(string $commentId, string $message): array
    {
        if (! $this->connected()) {
            return ['ok' => false, 'id' => null, 'error' => 'not_connected'];
        }
        try {
            $resp = Http::asForm()->timeout(30)->post("{$this->base()}/{$commentId}/private_replies", [
                'message' => $message,
                'access_token' => $this->token(),
            ]);
            if ($resp->successful() && ! $resp->json('error')) {
                return ['ok' => true, 'id' => $resp->json('id') ?? $resp->json('message_id'), 'error' => null];
            }

            return ['ok' => false, 'id' => null, 'error' => (string) ($resp->json('error.message') ?: 'http_'.$resp->status())];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * Publicly reply UNDER a comment (a normal page comment reply). Unlike a private reply this
     * needs only `pages_manage_engagement` — which we already have — so it works with no App Review.
     * The commenter (and everyone else on the post) sees the invite as a reply to them.
     *
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function publicReply(string $commentId, string $message): array
    {
        if (! $this->connected()) {
            return ['ok' => false, 'id' => null, 'error' => 'not_connected'];
        }
        try {
            $resp = Http::asForm()->timeout(30)->post("{$this->base()}/{$commentId}/comments", [
                'message' => $message,
                'access_token' => $this->token(),
            ]);
            if ($resp->successful() && ! $resp->json('error')) {
                return ['ok' => true, 'id' => $resp->json('id'), 'error' => null];
            }

            return ['ok' => false, 'id' => null, 'error' => (string) ($resp->json('error.message') ?: 'http_'.$resp->status())];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /** The feed STORY id (`{page}_{story}`) for a video/reel we posted — stored so comments map cheaply. */
    public function resolveStoryId(string $videoId): ?string
    {
        return $this->graphField($videoId, 'post_id');
    }

    /**
     * Fallback post→video mapping for comments on posts made before we stored remote_story_id:
     * a video post's `object_id` is the underlying video id, which matches remote_post_id.
     */
    public function resolvePostVideoId(string $postId): ?string
    {
        return $this->graphField($postId, 'object_id');
    }

    /** Public display name of a commenter, best-effort (used only to label the audit row). */
    public function userName(string $userId): ?string
    {
        return $this->graphField($userId, 'name');
    }

    /** GET /{id}?fields={field} → the scalar field value, or null on any failure. */
    private function graphField(string $id, string $field): ?string
    {
        if (! $this->connected() || $id === '') {
            return null;
        }
        try {
            $resp = Http::timeout(20)->get("{$this->base()}/{$id}", [
                'fields' => $field,
                'access_token' => $this->token(),
            ]);
            $val = $resp->successful() ? $resp->json($field) : null;

            return ($val === null || $val === '') ? null : (string) $val;
        } catch (Throwable) {
            return null;
        }
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.api_version', 'v21.0');
    }

    private function page(): string
    {
        return (string) (Setting::get('fb_page_id') ?: config('services.facebook.page_id'));
    }

    private function token(): string
    {
        return (string) (Setting::get('fb_page_token') ?: config('services.facebook.page_token'));
    }
}
