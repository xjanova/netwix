<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One site, one hostname.
 *
 * `www.netwix.online` and `netwix.online` both answered 200, and because the canonical tag is built
 * from `url()->current()` each one canonicalised to ITSELF — so Google saw two complete copies of
 * the site and split every ranking signal between them (it had already indexed
 * `www.netwix.online/login`). This 301s the www alias onto the APP_URL host, which is the same host
 * the sitemaps, canonical tags and JSON-LD all use.
 *
 * Deliberately narrow: it fires only for the literal "www." prefix of the CONFIGURED host, so it
 * cannot loop, and every other hostname (local dev, the box's own name, health checks) is left
 * alone. Unsafe methods pass through untouched — a 301 would turn a POST into a GET and eat the
 * request body.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $app = parse_url((string) config('app.url'));
        $host = $app['host'] ?? null;

        if ($host && $request->getHost() === 'www.'.$host && $request->isMethodSafe()) {
            $scheme = $app['scheme'] ?? 'https';

            return redirect()->to($scheme.'://'.$host.$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
