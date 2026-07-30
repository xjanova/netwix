<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make "จดจำฉันไว้" actually mean forever.
 *
 * Laravel issues the remember-me cookie once, at sign-in, for 400 days — the
 * ceiling browsers enforce on any cookie — and never issues it again. That
 * catches two kinds of member: the one who drops in every few months and
 * eventually finds the cookie has run out, and the daily visitor whose rolling
 * session means the cookie is never even consulted while it quietly ages out
 * underneath them. Either way they're asked to sign in again for no reason
 * they can see.
 *
 * So re-issue it on every authenticated request that presents one: tick the
 * box, come back within 400 days of your last visit, and you stay signed in
 * indefinitely.
 *
 * The value re-issued is the one the browser just sent — already proven valid
 * by this very request — so the member's remember_token is untouched and their
 * other devices are unaffected. Guests present no cookie and pay nothing.
 */
class RefreshRememberCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();

        if (method_exists($guard, 'getRecallerName')) {
            $name = $guard->getRecallerName();

            // EncryptCookies has already decrypted this on the way in, and will
            // re-encrypt whatever we queue on the way out — so it round-trips.
            $recaller = $request->cookies->get($name);

            // Only for a member who is genuinely signed in: a stale recaller on a
            // signed-out browser must be left alone to expire.
            if ($recaller && $guard->check()) {
                Cookie::queue(Cookie::forever($name, $recaller));
            }
        }

        return $next($request);
    }
}
