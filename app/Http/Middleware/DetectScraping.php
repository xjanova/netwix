<?php

namespace App\Http\Middleware;

use App\Support\ScrapeGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs [App\Support\ScrapeGuard] over the endpoints that carry our content.
 *
 * Registered on the `web` and `api` GROUPS, not the global stack. Global meant running before
 * StartSession and before route middleware, so `$request->user()` was always null and the `app_token`
 * attribute was never set — the admin exemption and the app exemption were both unreachable code, and
 * the only clause that ever evaluated was a raw `Authorization` header. The guard is only useful if it
 * can tell who is asking.
 *
 * Everything else is observation: in observe mode nothing is ever refused.
 */
class DetectScraping
{
    public function handle(Request $request, Closure $next): Response
    {
        // inspect() decides everything: whether this path is even watched, whether the caller is
        // exempt, and whether an enforcing guard should refuse. The block check used to run FIRST and
        // independently, so a flagged address was refused on every path on the site — including
        // /login and the admin panel, which is precisely where a wrongly-caught person (or the owner)
        // would go to fix it. It also ran before the exemptions, so an admin whose address had been
        // flagged before they signed in could never sign in.
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
