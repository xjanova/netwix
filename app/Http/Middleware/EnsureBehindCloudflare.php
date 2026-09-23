<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Support\EdgeSecret;
use App\Support\ScrapeGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse requests that did not come through Cloudflare.
 *
 * Everything protecting this site — the WAF, Bot Fight Mode, rate limiting, the managed challenge —
 * lives at Cloudflare's edge. None of it applies to somebody who connects to the origin's IP address
 * directly, and until now that worked: `curl -H 'Host: netwix.online' http://123.253.62.251/`
 * returned **200 and the real page**. The firewall lists port 80 in `TCP_IN` with no Cloudflare-only
 * restriction, and our own public IP is printed in our own `security_events` table, so finding it
 * takes no skill at all. Every edge protection was one HTTP request away from being irrelevant.
 *
 * Cloudflare stamps `CF-Connecting-IP` on everything it proxies. Checking for it is only a guess,
 * though: somebody who has reached the origin directly can type that header themselves (found
 * 2026-09-23 — a forged header does NOT spoof `$request->ip()`, since mod_remoteip only trusts
 * Cloudflare's ranges, but it did walk straight past this gate). The real proof is [EdgeSecret]: a
 * secret header only Cloudflare adds. Until it is set up and enforced, the presence check stands.
 *
 * Deliberately reversible from the database (`require_cloudflare`), because a mistake here takes the
 * whole site off the internet and a deploy is a slow way to undo that.
 *
 * Three things must keep working and are exempted by name:
 *   - `/.well-known/…` — Let's Encrypt validates over plain HTTP straight to the origin. Blocking it
 *     would silently break certificate renewal and take the site down weeks later, which is the worst
 *     possible failure shape.
 *   - our own machine — the canary, the storage probe and the admin preview call the site by hostname.
 *   - the loopback.
 */
class EnsureBehindCloudflare
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::flag('require_cloudflare', true)) {
            return $next($request);
        }

        // ACME HTTP-01 challenges reach the origin directly by design. Never gate certificate renewal.
        if (str_starts_with(ltrim($request->path(), '/'), '.well-known/')) {
            return $next($request);
        }

        if (ScrapeGuard::isOwnServer((string) $request->server->get('REMOTE_ADDR', ''))
            || ScrapeGuard::isOwnServer((string) $request->ip())) {
            return $next($request);
        }

        // Proof, when it has been set up: Cloudflare adds a secret header to everything it forwards, so
        // a request carrying it came through the edge. Presence of CF-Ray / CF-Connecting-IP (below) is
        // only a guess — anyone who reaches the origin directly can type those two headers themselves.
        $edge = EdgeSecret::check($request);
        if ($edge === true) {
            return $next($request);
        }
        if ($edge === false && EdgeSecret::enforcing()) {
            return response('Forbidden', 403);
        }

        if ($request->headers->has('CF-Connecting-IP') || $request->headers->has('CF-Ray')) {
            return $next($request);
        }

        // 403 and nothing else: no hostname, no hint about what was expected. Someone who reached this
        // response already knows the origin IP; there is no reason to also tell them why it failed.
        return response('Forbidden', 403);
    }
}
