<?php

namespace App\Services\Import\Sources;

/**
 * The registered Halim-theme sites. This is the ONE place to add a Halim source: append a config to
 * [self::all()] and it's wired everywhere (import UI, resolver, backup pool) via [SourceRegistry].
 *
 * A site is `backupPool: true` when it's an INDEPENDENT Halim site — its own catalogue AND its own
 * player CDN — so it can serve as a fallback stream for another site's un-playable title. Verify a
 * new site server-side (Thai IP) before enabling the pool flag: WP REST reachable, get.php resolves,
 * and its playerHost differs from the sites it would back up (a same-CDN sibling is useless as a
 * backup — if the CDN is down the "backup" is down too).
 */
class HalimSites
{
    /** @return HalimSiteConfig[] */
    public static function all(): array
    {
        return [self::anime108(), self::the24hdx()];
    }

    /**
     * 24-hdx.com — WordPress + Halim theme, Thai-dubbed/subbed MOVIES (~6,500 titles). The player
     * ajax lives on a SEPARATE subdomain, `lang` is required, and a title carries only some of
     * Thai/Sound Track × server 1/2/3 — hence multiple langs+servers to try.
     *
     * 2026-07-28: the site put a Cloudflare MANAGED CHALLENGE on its WordPress layer — both
     * `/wp-json/*` and `api.24-hdx.com` answer server-side requests with `cf-mitigated: challenge`
     * 403, which killed playback site-wide (no player hash → no stream). The plain title pages, the
     * player host and the whole segment CDN are untouched. Two ways around it, both verified from the
     * prod IP:
     *   - resolve → the sibling alias `api.24-hds.com/get.php` is the SAME backend, un-challenged,
     *     and returns the identical player hash. That's the apiUrl below.
     *   - catalogue → no un-challenged REST route survives (every alias 301s back to www.24-hdx.com),
     *     so `rssCatalogFallback` crawls /feed/?paged=N instead. See [HalimSource::fetchCatalogViaRss].
     * If the alias ever gets challenged too, re-run the recon: the fix is whichever host still answers
     * `action=halim_ajax_player` with an `index_th.php?id=…` iframe.
     */
    public static function the24hdx(): HalimSiteConfig
    {
        return new HalimSiteConfig(
            id: '24hdx',
            displayName: '24-HDX (ภาพยนตร์)',
            base: 'https://www.24-hdx.com',
            apiUrl: 'https://api.24-hds.com/get.php',   // alias subdomain — the 24-hdx one is CF-challenged
            playerHost: 'https://main.24playerhd.com',
            langs: ['Thai', 'Sound Track'],
            servers: ['1', '2', '3'],   // the title page now offers a 3rd ("สำรอง") server
            defaultContentType: 'movie',
            genreMap: [
                'action' => 'แอ็กชัน', 'action-2' => 'แอ็กชัน', 'superhero' => 'แอ็กชัน',
                'marvel-universe' => 'แอ็กชัน', 'war' => 'แอ็กชัน',
                'adventure' => 'ผจญภัย', 'adventure-2' => 'ผจญภัย',
                'comedy' => 'ตลก', 'comedy-2' => 'ตลก',
                'drama' => 'ดราม่า', 'drama-2' => 'ดราม่า', 'biography' => 'ดราม่า', 'family' => 'ดราม่า',
                'romance' => 'โรแมนติก', 'musical' => 'โรแมนติก',
                'horror' => 'สยองขวัญ', 'horror-2' => 'สยองขวัญ',
                'thriller' => 'อาชญากรรม', 'thriller-2' => 'อาชญากรรม', 'crime' => 'อาชญากรรม',
                'crime-2' => 'อาชญากรรม', 'mystry' => 'อาชญากรรม',
                'fantasy' => 'แฟนตาซี & ไซไฟ', 'fantasy-2' => 'แฟนตาซี & ไซไฟ', 'sci-fi' => 'แฟนตาซี & ไซไฟ',
                'history' => 'ย้อนยุค',
            ],
            umbrellaGenre: null,                 // real movies — no umbrella, so they show on /movies
            seriesCatSlug: 'series',             // "series" category present → series, else movie
            siteTagRegex: '\|\s*24-?hdx',
            stripYearParen: true,
            yearFromTitleParen: true,
            episodeMode: HalimSiteConfig::EP_OPTION_NUM,
            backupPool: true,
            adultCatSlug: '18',                  // 24-hdx "18" category (~168 titles) → import as 18+/VIP
            rssCatalogFallback: true,            // WP REST is Cloudflare-challenged — crawl /feed/ instead
            catNameToSlug: [                     // RSS carries category NAMES; the Thai ones need a map
                'ดูซีรี่ย์' => 'series',
                'ซีรี่ย์' => 'series',
                'หนัง Rate 18+' => '18',
                'Rate 18+' => '18',
                '18+' => '18',
            ],
            // The site's own header autocomplete, on the same un-challenged alias family as apiUrl.
            // It is the only working way to look a 24-hdx title up by name: "?s=" and "/search/…" both
            // answer 200 with a ZERO-byte body (measured 2026-08-19), while this returns JSON rows
            // carrying the poster path — which is what heals the 37 24-hdx titles imported with no
            // cover at all. See [App\Support\PosterSearch].
            autocompleteUrl: 'https://stat.24-hds.com/search_autocomplete?site_id=1',
        );
    }

    /**
     * anime108.com — WordPress + HalimMovies theme, CN/JP anime (~1,600 titles). The player ajax is
     * the same-domain /api/get.php (the site's admin-ajax.php is Cloudflare-blocked); `lang=Thai`,
     * server 1 is enough. Every title also lands under the "อนิเมะ" umbrella genre.
     */
    public static function anime108(): HalimSiteConfig
    {
        return new HalimSiteConfig(
            id: 'anime108',
            displayName: 'Anime108 (การ์ตูน/อนิเมะ)',
            base: 'https://www.anime108.com',
            apiUrl: 'https://www.anime108.com/api/get.php',
            playerHost: 'https://main.108player.com',
            langs: ['Thai'],
            servers: ['1'],
            defaultContentType: 'series',
            genreMap: [
                'action' => 'แอ็กชัน', 'martial-arts' => 'แอ็กชัน', 'super-power' => 'แอ็กชัน', 'samurai' => 'แอ็กชัน',
                'adventure' => 'ผจญภัย', 'isekai' => 'ผจญภัย',
                'comedy' => 'ตลก', 'parody' => 'ตลก',
                'drama' => 'ดราม่า', 'slice-of-life' => 'ดราม่า', 'josei' => 'ดราม่า', 'seinen' => 'ดราม่า',
                'fantasy' => 'แฟนตาซี & ไซไฟ', 'sci-fi' => 'แฟนตาซี & ไซไฟ', 'magic' => 'แฟนตาซี & ไซไฟ',
                'supernatural' => 'แฟนตาซี & ไซไฟ', 'mecha' => 'แฟนตาซี & ไซไฟ', 'space' => 'แฟนตาซี & ไซไฟ',
                'romance' => 'โรแมนติก', 'harem' => 'โรแมนติก', 'shoujo' => 'โรแมนติก',
                'horror' => 'สยองขวัญ', 'demons' => 'สยองขวัญ', 'vampire' => 'สยองขวัญ',
                'mystery' => 'อาชญากรรม', 'detective' => 'อาชญากรรม', 'suspense' => 'อาชญากรรม', 'psychological' => 'อาชญากรรม',
            ],
            umbrellaGenre: 'อนิเมะ',
            movieCatSlug: 'the-movie',            // "the-movie" category present → movie, else series
            siteTagRegex: '\|\s*anime108',
            stripYearParen: false,
            yearFromTitleParen: false,
            episodeMode: HalimSiteConfig::EP_SLUG,
            backupPool: true,
        );
    }
}
