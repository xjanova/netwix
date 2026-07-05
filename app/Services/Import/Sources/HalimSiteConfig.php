<?php

namespace App\Services\Import\Sources;

/**
 * Per-site knobs for a Halim-theme WordPress source (see [HalimSource]). Everything that differs
 * between 24-hdx / anime108 / any future Halim site is a value here, so adding a site =
 * registering one more config in [HalimSites] — never a new class.
 *
 * The shared Halim recipe (identical across sites, lives in HalimSource): catalogue via
 * WP REST /wp-json/wp/v2/posts (+ /media posters, /categories genres), resolve via
 * POST {apiUrl} (action=halim_ajax_player, postid, episode, server, lang) → iframe
 * {playerHost}/index_th.php?id={hash} → HLS master newplaylist/{hash}/{hash}.m3u8 → best variant.
 */
class HalimSiteConfig
{
    /** Episode-list parsing strategy for a series detail page. */
    public const EP_OPTION_NUM = 'option_num'; // <option value="N"> ตอนที่ N   (24-hdx)
    public const EP_SLUG = 'ep_slug';          // <a href="/{slug}-ep-N/">        (anime108)

    /**
     * @param  string  $id  stable source id, e.g. "24hdx" (must stay constant — DB rows reference it)
     * @param  string  $base  site origin, e.g. "https://www.24-hdx.com"
     * @param  string  $apiUrl  absolute Halim player-ajax endpoint (get.php) — subdomain OR {base}/api/get.php
     * @param  string  $playerHost  HLS player origin, e.g. "https://main.24playerhd.com"
     * @param  string[]  $langs  player `lang` values to try, in preference order (Thai dub, then Sound Track)
     * @param  string[]  $servers  player `server` values to try, in preference order
     * @param  array<string,string>  $genreMap  source category slug → NetWix genre name (Thai)
     * @param  ?string  $umbrellaGenre  genre every title from this source is always filed under (e.g. "อนิเมะ")
     * @param  ?string  $seriesCatSlug  category slug that marks a title as a series (else movie) — mutually
     *                                   exclusive with $movieCatSlug
     * @param  ?string  $movieCatSlug  category slug that marks a title as a movie (else series)
     * @param  ?string  $siteTagRegex  trailing "| sitename" fragment to strip from the display title
     * @param  bool  $stripYearParen  strip a trailing "(YYYY)" from the display title
     * @param  bool  $yearFromTitleParen  read the film year from "(YYYY)" in the title (else only post date)
     * @param  string  $episodeMode  one of the EP_* constants
     * @param  bool  $backupPool  eligible to serve as a backup stream for another site's suspended title
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $base,
        public string $apiUrl,
        public string $playerHost,
        public array $langs = ['Thai'],
        public array $servers = ['1'],
        public string $defaultContentType = 'movie',
        public array $genreMap = [],
        public ?string $umbrellaGenre = null,
        public ?string $seriesCatSlug = null,
        public ?string $movieCatSlug = null,
        public ?string $siteTagRegex = null,
        public bool $stripYearParen = false,
        public bool $yearFromTitleParen = false,
        public string $episodeMode = self::EP_OPTION_NUM,
        public bool $backupPool = false,
    ) {}
}
