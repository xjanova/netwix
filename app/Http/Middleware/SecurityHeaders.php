<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser-side security headers every response was missing (checked on the live /login page
 * 2026-09-23: no HSTS, no framing rule, no nosniff, no referrer policy — neither from us nor from
 * Cloudflare).
 *
 * Deliberately NOT a script Content-Security-Policy: the pages lean on inline Alpine, AdSense and
 * third-party players, and a CSP strict enough to matter would break them. `frame-ancestors` is the
 * one directive that costs nothing — nobody has a reason to show our pages inside theirs, and doing
 * so is how a click on "ลบ" or "ซื้อ" gets hijacked.
 *
 * Only fills in what a response has not set itself.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
        // HSTS only over HTTPS (a browser ignores it on plain HTTP anyway). No includeSubDomains: a
        // future subdomain that is not HTTPS-ready must not be locked out by this one.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=15552000';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
