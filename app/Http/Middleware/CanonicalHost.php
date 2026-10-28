<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One site, one origin.
 *
 * `www.netwix.online` and `netwix.online` both answered 200, and because the canonical tag is built
 * from `url()->current()` each one canonicalised to ITSELF — so Google saw two complete copies of
 * the site and split every ranking signal between them (it had already indexed
 * `www.netwix.online/login`). This 301s the www alias onto the APP_URL host, which is the same host
 * the sitemaps, canonical tags and JSON-LD all use.
 *
 * The scheme had the same hole (found 2026-10-28): `http://netwix.online/title/…` answered 200 with
 * `<link rel="canonical" href="http://…">`, so every page had an http twin that also claimed to be
 * the original, and Google had indexed a batch of them (`site:netwix.online` showed `http://` rows).
 * When APP_URL is https, a plain-http request on the canonical host is 301'd onto it as well.
 *
 * "Plain http" means the VISITOR's scheme, not the hop to the origin: under Cloudflare's Flexible
 * mode every request reaches us over http, so `isSecure()` alone would redirect https visitors to
 * https forever. Cloudflare's `CF-Visitor` / `X-Forwarded-Proto` say what the visitor used. Someone
 * who forges them only skips their own redirect.
 *
 * Deliberately narrow: it fires only for the literal "www." prefix of the CONFIGURED host, or for
 * http on that host, so it cannot loop, and every other hostname (local dev, the box's own name,
 * health checks) is left alone. Unsafe methods pass through untouched — a 301 would turn a POST into
 * a GET and eat the request body. `/.well-known/` is never touched: Let's Encrypt validates over
 * plain http straight to the origin (see EnsureBehindCloudflare).
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $app = parse_url((string) config('app.url'));
        $host = $app['host'] ?? null;
        $scheme = $app['scheme'] ?? 'https';

        if (! $host || ! $request->isMethodSafe() || str_starts_with($request->path(), '.well-known/')) {
            return $next($request);
        }

        $isAlias = $request->getHost() === 'www.'.$host;
        $isPlainHttp = $scheme === 'https' && $request->getHost() === $host && ! $this->visitorUsedHttps($request);

        if ($isAlias || $isPlainHttp) {
            return redirect()->to($scheme.'://'.$host.$request->getRequestUri(), 301);
        }

        return $next($request);
    }

    private function visitorUsedHttps(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        // CF-Visitor: {"scheme":"https"}
        $visitor = json_decode((string) $request->headers->get('CF-Visitor', ''), true);
        if (is_array($visitor) && ($visitor['scheme'] ?? null) === 'https') {
            return true;
        }

        return strtolower((string) $request->headers->get('X-Forwarded-Proto', '')) === 'https';
    }
}
