<?php

namespace App\Support;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Which profile is this device watching as?
 *
 * The pick lives in the session, but the session is not the durable copy of it.
 * When a session finally expires and the "remember me" cookie signs the member
 * straight back in, the fresh session is empty — so they land on the profile
 * picker, which reads exactly like having been logged out. Mirroring the pick
 * into a long-lived cookie makes the return silent.
 *
 * The cookie only names a profile id: ownership is re-checked against the
 * authenticated user on every read, so a forged value can never reach someone
 * else's profile — it just falls through to the picker. (Laravel encrypts and
 * signs it on the way out regardless, via EncryptCookies.)
 */
class ActiveProfile
{
    public const COOKIE = 'nx_profile';

    /** ~13 months — browsers cap cookie lifetime at 400 days anyway. */
    private const COOKIE_MINUTES = 60 * 24 * 400;

    /** Session first, then this device's cookie. A cookie hit re-seeds the session. */
    public static function resolve(Request $request): ?Profile
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        $sessionId = $request->session()->get('profile_id');
        if ($sessionId && $profile = $user->profiles()->find($sessionId)) {
            return $profile;
        }

        $cookieId = $request->cookie(self::COOKIE);
        if ($cookieId && $profile = $user->profiles()->find($cookieId)) {
            $request->session()->put('profile_id', $profile->id);

            return $profile;
        }

        // Neither resolved (deleted profile, different account on a shared device,
        // tampered cookie) — clear the stale id so the next request doesn't retry it.
        $request->session()->forget('profile_id');

        return null;
    }

    /** Record the pick in both places. */
    public static function remember(Request $request, Profile $profile): void
    {
        $request->session()->put('profile_id', $profile->id);

        Cookie::queue(Cookie::make(self::COOKIE, (string) $profile->id, self::COOKIE_MINUTES));
    }

    /** Drop the pick — profile deleted, account suspended, or an explicit sign-out. */
    public static function forget(Request $request): void
    {
        $request->session()->forget('profile_id');

        Cookie::queue(Cookie::forget(self::COOKIE));
    }
}
