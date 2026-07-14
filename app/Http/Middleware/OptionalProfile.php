<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile middleware for pages a GUEST may open (e.g. /watch): a signed-in member
 * still gets the full EnsureProfileSelected behaviour (suspension kick, profile
 * pick, shared view state), while a guest passes straight through — the views fall
 * back to their @guest branches and the register-CTA (campaign) banner.
 */
class OptionalProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        return app(EnsureProfileSelected::class)->handle($request, $next);
    }
}
