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
        $user = $plain ? AppToken::resolveUser($plain) : null;

        if (! $user) {
            return response()->json(['success' => false, 'error' => 'unauthenticated'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
