<?php

namespace App\Http\Middleware;

use App\Support\BrowserRelay;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrowserRelay
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(BrowserRelay::enabled(), 404);
        abort_unless(hash_equals((string) config('services.rongyok.browser_relay_token'), (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
