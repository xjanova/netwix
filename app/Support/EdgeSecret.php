<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proof that a request came through Cloudflare, instead of a guess from its headers.
 *
 * EnsureBehindCloudflare let a request in when it merely HAD a `CF-Connecting-IP` or `CF-Ray` header.
 * Cloudflare sets those — but so can anyone who connects to the origin IP directly, and doing so
 * skipped the WAF, Bot Fight Mode, the edge rate limits and the edge cache in one header.
 *
 * The fix is a shared secret that only Cloudflare adds: a Transform Rule sets [self::HEADER] on every
 * request it forwards to us, and a request without the right value did not come from Cloudflare.
 *
 * Rolled out in two steps so it can never lock the site out: while the secret is set but not enforced
 * the old presence check still decides, and the admin page shows whether Cloudflare's header is
 * arriving. Enforcement can only be switched on after it has been seen.
 */
class EdgeSecret
{
    public const HEADER = 'X-NetWix-Edge';

    /** Enforcement may only be switched on if the header arrived this recently. */
    public const SEEN_WITHIN = 600;

    private const SEEN_KEY = 'edge_secret:last_ok';

    public static function secret(): string
    {
        return (string) Setting::get('cf_edge_secret', '');
    }

    public static function configured(): bool
    {
        return self::secret() !== '';
    }

    public static function enforcing(): bool
    {
        return self::configured() && Setting::flag('cf_edge_enforce', false);
    }

    /** True: carries the secret. False: a secret is set and this request lacks it. Null: none set. */
    public static function check(Request $request): ?bool
    {
        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        $ok = hash_equals($secret, (string) $request->headers->get(self::HEADER, ''));
        $ok ? self::noteSeen() : self::noteMissing();

        return $ok;
    }

    /** Unix time the header last arrived intact, or null. */
    public static function lastSeen(): ?int
    {
        $at = Cache::get(self::SEEN_KEY);

        return is_numeric($at) ? (int) $at : null;
    }

    public static function seenRecently(): bool
    {
        $at = self::lastSeen();

        return $at !== null && now()->getTimestamp() - $at <= self::SEEN_WITHIN;
    }

    /** Requests this hour that came in without the secret (while one is set). */
    public static function missingThisHour(): int
    {
        return (int) Cache::get(self::missKey(), 0);
    }

    /** A new secret, returned once for the admin to paste into Cloudflare. Enforcement restarts off. */
    public static function generate(): string
    {
        $secret = Str::random(48);
        Setting::write('cf_edge_secret', $secret);
        Setting::write('cf_edge_enforce', '0');
        Cache::forget(self::SEEN_KEY);

        return $secret;
    }

    public static function clear(): void
    {
        Setting::write('cf_edge_secret', null);
        Setting::write('cf_edge_enforce', '0');
        Cache::forget(self::SEEN_KEY);
    }

    private static function noteSeen(): void
    {
        try {
            // Written at most once a minute: this runs on every request.
            $at = self::lastSeen();
            if ($at === null || now()->getTimestamp() - $at >= 60) {
                Cache::put(self::SEEN_KEY, now()->getTimestamp(), now()->addDay());
            }
        } catch (Throwable) {
            // never let bookkeeping decide whether a request gets in
        }
    }

    private static function noteMissing(): void
    {
        try {
            Cache::add(self::missKey(), 0, now()->addHours(2));
            Cache::increment(self::missKey());
        } catch (Throwable) {
            // same
        }
    }

    private static function missKey(): string
    {
        return 'edge_secret:miss:'.now()->format('YmdH');
    }
}
