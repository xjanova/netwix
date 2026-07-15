<?php

namespace App\Http\Middleware;

use App\Models\AppToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token guard for the mobile app's authenticated endpoints (/api/app/*).
 * Resolves `Authorization: Bearer <token>` to a User and binds it to the request
 * (no session). Returns a JSON 401 envelope on failure.
 */
class AuthenticateAppToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain ? AppToken::resolve($plain) : null;

        if (! $token || ! $token->user) {
            return response()->json(['success' => false, 'error' => 'unauthenticated'], 401);
        }

        $user = $token->user;
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        // Bind the profile this DEVICE is watching as, so MaturityScope applies on
        // the app's member routes too — a kids profile must not reach adult titles,
        // including via route-model binding. Server-side by design: the choice
        // lives on the token, not in a header the client could simply omit.
        $request->attributes->set('profile', $token->activeProfile());
        $request->attributes->set('app_token', $token);

        return $next($request);
    }
}
