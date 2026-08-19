<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;

/**
 * Parses a source site's SEARCH RESULTS page into poster candidates.
 *
 * Every site we import from is WordPress, and their result markup collapses into one shape: an
 * anchor into the site that wraps a `<img>` served out of `/wp-content/uploads/`. The title rides
 * along on the anchor's `title=` or the image's `alt=` — Dooplay themes (animeruka, 9nung,
 * goseries4k) put it on the alt, wow-drama's theme puts it on the anchor. Matching that pair rather
 * than each theme's class names means one parser covers every site, and a theme reskin doesn't
 * silently return zero results.
 *
 * Verified against the live markup of animeruka / 9.9nung / wow-drama on 2026-08-19.
 */
class SiteSearchScraper
{
    /**
     * Poster candidates from a search-results page's HTML.
     *
     * @param  string  $base  site origin, e.g. "https://animeruka.com" — only links into it count
     * @return PosterCandidate[]
     */
    public static function candidates(string $html, string $base, int $limit = 8): array
    {
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $out = [];
        $seen = [];

        // <a href="…">…<img …>…</a> — non-greedy so each result item matches on its own.
        if (! preg_match_all('~<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.{0,1200}?)</a>~is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($m as $hit) {
            [$whole, $href, $inner] = $hit;
            if (! str_contains($inner, '<img')) {
                continue;
            }
            // Only links into the source site itself — skips ad/network anchors entirely.
            if ($host !== '' && ! str_contains($href, $host)) {
                continue;
            }
            $image = self::imageIn($inner);
            if ($image === null) {
                continue;
            }
            $image = self::absolute($image, $base);
            // Uploaded media only. The logo, sprites and icons all live outside /uploads/, so this one
            // test drops the site chrome that every page repeats above its results.
            if (! str_contains($image, '/wp-content/uploads/')) {
                continue;
            }
            // The site's own logo lives in /uploads/ too and rides along on the header's home link,
            // so every search would otherwise offer the site logo as a cover for the first slot.
            if (preg_match('~/(?:[^/]*(?:logo|cropped-)[^/]*)$~i', $image)) {
                continue;
            }
            if (isset($seen[$image])) {
                continue;
            }
            $seen[$image] = true;

            $title = self::attr($whole, 'title') ?? self::attr($inner, 'alt') ?? '';
            $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title === '') {
                continue;
            }

            $out[] = new PosterCandidate(
                title: $title,
                image: $image,
                page: self::absolute($href, $base),
            );
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Run a WordPress site's own search ("?s=") and parse the results — the whole [SearchesPosters]
     * implementation for every Dooplay/WP source, so each one is a call rather than a copy.
     *
     * Failure is silent and empty on purpose: this runs behind an admin clicking "หาปกให้หน่อย" over
     * a list of sources, and one site being down or slow must leave the others' answers intact.
     *
     * @return PosterCandidate[]
     */
    public static function searchWordPress(PendingRequest $http, string $base, string $title, int $limit = 8): array
    {
        return self::searchAt($http, $base, rtrim($base, '/').'/', 's', $title, $limit);
    }

    /**
     * Same, for a site whose search is NOT WordPress's own "?s=".
     *
     * Themes move it: the Halim sites (24-hdx, anime108) search at `/search_movie/?keyword=…` and
     * their `?s=` is cached to an empty 200, so calling the wrong one returns silence rather than an
     * error. The trailing slash on the path matters there too — without it the site 301s.
     *
     * @param  string  $url  absolute search URL
     * @param  string  $param  query parameter carrying the search text
     * @return PosterCandidate[]
     */
    public static function searchAt(PendingRequest $http, string $base, string $url, string $param, string $title, int $limit = 8): array
    {
        $title = trim($title);
        if ($title === '') {
            return [];
        }
        try {
            $resp = $http->get($url, [$param => $title]);
        } catch (\Throwable) {
            return [];
        }
        if (! $resp->ok()) {
            return [];
        }

        return self::candidates($resp->body(), $base, $limit);
    }

    /**
     * The URLs to try when DOWNLOADING a scraped cover, best quality first.
     *
     * Listing pages link the resized derivative WordPress generated for that grid — a 150x150 thumb
     * on animeruka, 187x269 on Halim — which is far below the 400px our own cards paint. The original
     * upload always exists (the derivatives are cut from it), so strip the `-WIDTHxHEIGHT` suffix and
     * prefer that, keeping the scraped URL as the fallback for the rare theme that links something
     * other than a derivative.
     *
     * @return string[]
     */
    public static function downloadOrder(string $url): array
    {
        $full = preg_replace('~-\d{2,4}x\d{2,4}(\.(?:jpe?g|png|webp|gif))$~i', '$1', $url);

        return ($full !== null && $full !== $url) ? [$full, $url] : [$url];
    }

    /** First real image URL inside a fragment — lazy-loading attributes win over the placeholder src. */
    private static function imageIn(string $fragment): ?string
    {
        foreach (['data-lazy-src', 'data-src', 'src'] as $attr) {
            if (preg_match('~<img[^>]+'.preg_quote($attr, '~').'=[\'"]([^\'"]+)[\'"]~i', $fragment, $m)) {
                $url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // Lazy-loaded markup parks an inline SVG in `src` — that is not the cover.
                if ($url !== '' && ! str_starts_with($url, 'data:')) {
                    return $url;
                }
            }
        }

        return null;
    }

    private static function attr(string $tag, string $name): ?string
    {
        return preg_match('~\s'.preg_quote($name, '~').'=[\'"]([^\'"]*)[\'"]~i', $tag, $m) && trim($m[1]) !== ''
            ? $m[1]
            : null;
    }

    /** Resolve a possibly-relative URL against the site origin. */
    public static function absolute(string $url, string $base): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        return rtrim($base, '/').'/'.ltrim($url, '/');
    }
}
