<?php

namespace App\Support;

/**
 * Extracts a poster/cover image URL from a scraped source page — the OpenGraph / Twitter-card image
 * that virtually every WordPress / Dooplay / Halim title page exposes. Used by the poster backfill
 * ([App\Support\PosterBackfill]) to re-fetch a cover when a title's hotlinked poster has gone dead.
 */
class PosterScraper
{
    /** The og:image (or twitter:image / image_src) URL from a title page's HTML, or null. */
    public static function fromHtml(string $html): ?string
    {
        // Match the meta tag in either attribute order, single- or double-quoted.
        $patterns = [
            '~<meta[^>]+property=[\'"]og:image(?::url)?[\'"][^>]+content=[\'"]([^\'"]+)[\'"]~i',
            '~<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+property=[\'"]og:image(?::url)?[\'"]~i',
            '~<meta[^>]+name=[\'"]twitter:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]~i',
            '~<link[^>]+rel=[\'"]image_src[\'"][^>]+href=[\'"]([^\'"]+)[\'"]~i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $html, $m)) {
                $url = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (str_starts_with($url, 'http')) {
                    return $url;
                }
            }
        }

        return null;
    }
}
