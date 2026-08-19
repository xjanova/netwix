<?php

namespace App\Http\Middleware;

use App\Support\ScrapeGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs [App\Support\ScrapeGuard] over the endpoints that carry our content.
 *
 * Placed on the global stack rather than a route group because a scraper picks its own targets — the
 * guard decides internally which paths it cares about, so a route added next month is covered without
 * anyone remembering to opt it in.
 *
 * Refusing happens BEFORE the request is handled, so a blocked client costs one cached lookup instead
 * of a database query and a rendered response. Everything else is observation only.
 */
class DetectScraping
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ScrapeGuard::enforcing() && ScrapeGuard::isBlocked((string) $request->ip())) {
            return $this->refuse($request);
        }

        if (ScrapeGuard::inspect($request)) {
            return $this->refuse($request);
        }

        return $next($request);
    }

    /**
     * 429 rather than 403: it says "too much", not "you are forbidden", which is both truer and less
     * informative to someone probing for the shape of our defences. Retry-After is honest about the
     * block window so a mistakenly-caught viewer's browser can recover on its own.
     */
    private function refuse(Request $request): Response
    {
        $retry = 3600;

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => 'คำขอถี่เกินไป กรุณาลองใหม่ภายหลัง',
            ], 429)->header('Retry-After', $retry);
        }

        return response('คำขอถี่เกินไป กรุณาลองใหม่ภายหลัง', 429)
            ->header('Retry-After', $retry);
    }
}
