<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging (HTTP v1) sender — dependency-free: signs the
 * service-account JWT with openssl and exchanges it for an OAuth token itself,
 * so no composer package is needed on the server.
 *
 * Credentials: the service-account JSON (project netwix-online) lives in the
 * `fcm_service_account` Setting (SECRET key → encrypted at rest, editable from
 * the admin "แจ้งเตือนในแอป" page). Broadcasts go to the TOPIC matching the
 * notification's category (new_content / news / other) — the app subscribes
 * per the user's toggles, so muting a category stops the push at FCM itself.
 */
class FcmSender
{
    private const TOKEN_CACHE = 'fcm.access_token';

    /** True when a service-account has been configured. */
    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /**
     * Push an admin notification to its category topic. Best-effort: returns
     * true when FCM accepted it, false (and logs) otherwise — a failed push
     * must never block posting, the in-app inbox still delivers it.
     */
    public static function sendNotification(AppNotification $n): bool
    {
        $creds = self::credentials();
        if (! $creds) {
            return false;
        }

        try {
            $token = self::accessToken($creds);

            $message = [
                'topic' => $n->category,
                'notification' => [
                    'title' => $n->title,
                    'body' => mb_strimwidth($n->body, 0, 500, '…', 'UTF-8'),
                ],
                'data' => [
                    'notice_id' => (string) $n->id,
                    'category' => $n->category,
                    'link_url' => (string) ($n->link_url ?? ''),
                ],
                // No channel_id: an unknown channel would silence the banner on
                // Android 8+; omitting it lets the FCM SDK use its fallback channel.
                'android' => ['priority' => 'high'],
            ];

            // Only attach android.notification when there is something in it —
            // an empty PHP array JSON-encodes as [] (array, not object) and FCM
            // rejects the whole message with INVALID_ARGUMENT.
            if ($n->image_url) {
                $message['android']['notification'] = ['image' => $n->image_url];
            }

            $res = Http::withToken($token)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send", [
                    'message' => $message,
                ]);

            if (! $res->successful()) {
                Log::warning('fcm send failed', ['status' => $res->status(), 'body' => mb_strimwidth($res->body(), 0, 500)]);
            }

            return $res->successful();
        } catch (Throwable $e) {
            Log::warning('fcm send error: '.$e->getMessage());

            return false;
        }
    }

    // ------------------------------------------------------------- internals

    private static function credentials(): ?array
    {
        $raw = Setting::get('fcm_service_account');
        if (! $raw) {
            return null;
        }
        $j = json_decode($raw, true);

        return (is_array($j) && isset($j['client_email'], $j['private_key'], $j['project_id'])) ? $j : null;
    }

    /** OAuth2 access token via a self-signed service-account JWT (cached ~50min). */
    private static function accessToken(array $creds): string
    {
        return Cache::remember(self::TOKEN_CACHE, now()->addMinutes(50), function () use ($creds) {
            $now = time();
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

            $segments = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
                .'.'.$b64(json_encode([
                    'iss' => $creds['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));

            if (! openssl_sign($segments, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('fcm: JWT signing failed');
            }

            $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $segments.'.'.$b64($signature),
            ]);

            $token = $res->json('access_token');
            if (! $token) {
                throw new \RuntimeException('fcm: token exchange failed ('.$res->status().')');
            }

            return $token;
        });
    }
}
