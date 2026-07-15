<?php

namespace App\Http\Middleware;

use App\Models\AppToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional bearer guard for the mobile app's PUBLIC catalogue endpoints — the
 * counterpart to OptionalProfile on the web. A guest passes straight through; a
 * valid `Authorization: Bearer <token>` resolves to the User and binds their
 * default profile, so MaturityScope can hide adult titles from kids profiles.
 *
 * An invalid/expired token is treated as a guest rather than a 401: these routes
 * are browsable without an account, and a stale token must never black out the
 * catalogue. Endpoints that require a member keep using auth.apptoken.
 */
class OptionalAppToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? AppToken::resolve($plain) : null;

        if ($token && $token->user) {
            $user = $token->user;
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('profile', $token->activeProfile());
            $request->attributes->set('app_token', $token);
        }

        return $next($request);
    }
}
