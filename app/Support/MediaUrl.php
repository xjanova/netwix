<?php

namespace App\Support;

/**
 * Makes a stored media URL safe to hand to ANY client, not just a browser.
 *
 * Roughly 2,000 of our published titles still hotlink a cover whose filename is Thai —
 * `https://rongyok.com/images/poster/โปรดรักฉัน…-2026-8490.jpg` is stored exactly like that. Sources
 * serve those from nginx, which answers **400** to raw UTF-8 in a request path and 200 to the
 * percent-encoded form.
 *
 * A browser hides that difference: it encodes the path itself before putting it on the wire, so
 * every web page rendered these fine. The mobile app's HTTP client sends the string as given, gets
 * the 400, and falls back to the placeholder gradient — which is why covers were missing on mobile
 * and only on mobile (owner, 2026-08-16). The API was handing out a URL only one kind of client
 * could actually fetch.
 *
 * Encoding is done here, once, at the point where a stored path becomes a public URL, so web, the
 * app API and anything added later all get the same usable string.
 */
class MediaUrl
{
    /**
     * Percent-encode the path of an absolute URL, leaving scheme/host/query/fragment alone.
     *
     * Idempotent: each segment is decoded before it is re-encoded, so a URL that already carries
     * `%E0%B9%81…` comes back unchanged instead of turning into `%25E0%25B9%2581…`. That matters
     * because our sources are mixed — some feeds hand us encoded URLs and some raw ones — and this
     * runs on every render.
     */
    /**
     * The URL an ADMIN page should use for a poster.
     *
     * Our own stored covers are returned untouched. Anything still hosted by a source goes through
     * the admin image proxy, because sources answer a hotlinked request from a browser with a house
     * advert — rongyok serves a green "rongyok.com" banner — while the same URL fetched from our
     * server returns the real artwork. Without this the admin grids fill with adverts and the
     * catalogue cannot be reviewed at all.
     *
     * Only for admin surfaces. Public pages keep hotlinking, since a viewer's browser is served the
     * real image, and proxying tens of thousands of covers would put that traffic on our own box.
     */
    public static function adminPoster(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! str_starts_with($url, 'http')) {
            return $url;   // relative → our own disk
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $ours = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        if ($host !== '' && $ours !== '' && str_contains($host, $ours)) {
            return $url;   // already ours
        }

        return route('admin.img-proxy', ['url' => $url]);
    }

    public static function encodePath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['path']) || $parts['path'] === '') {
            return $url;
        }

        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $parts['path'])
        ));

        $out = '';
        if (isset($parts['scheme'])) {
            $out .= $parts['scheme'].'://';
        }
        if (isset($parts['user'])) {
            $out .= $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@';
        }
        $out .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $out .= ':'.$parts['port'];
        }
        $out .= $path;
        if (isset($parts['query'])) {
            $out .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $out .= '#'.$parts['fragment'];
        }

        return $out;
    }
}
