<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile (free, privacy-friendly CAPTCHA-alternative) bot protection.
 * Dormant until BOTH a site key and secret are set in admin Settings — so login/register/
 * comments keep working out of the box, and the widget only appears once keys are pasted.
 */
class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function siteKey(): ?string
    {
        return Setting::get('turnstile_site_key');
    }

    /** Only active when the owner has configured a widget (site key + secret). */
    public static function enabled(): bool
    {
        return filled(Setting::get('turnstile_site_key')) && filled(Setting::get('turnstile_secret'));
    }

    /**
     * Validate a widget token with Cloudflare. Fails CLOSED on a genuinely bad/absent token,
     * but fails OPEN if Cloudflare itself is unreachable — an outage there must not lock users out.
     */
    public static function passes(?string $token, ?string $ip = null): bool
    {
        if (! self::enabled()) {
            return true;   // not configured → never block
        }
        if (! filled($token)) {
            return false;
        }

        try {
            $resp = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => Setting::get('turnstile_secret'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            // Non-2xx from Cloudflare = their problem, not the user's → allow through.
            return $resp->successful() ? (bool) $resp->json('success') : true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
