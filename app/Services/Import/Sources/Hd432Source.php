<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\BackupPoolSource;
use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesPoster;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Support\PosterScraper;
use App\Support\SynopsisScraper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * hd432.com (ดูหนังออนไลน์) — WordPress on a bespoke "movie" theme, Thai-dubbed/subbed MOVIES
 * (~1,500 titles). Reverse-engineered 2026-07-28 from the prod IP. Streams are HLS through NetWix's
 * server-side proxy ([App\Http\Controllers\StreamController]) — no new player infra.
 *
 * Its REST API is shut (a security plugin answers /wp-json with 401/403) and query-string URLs are
 * swallowed by its page cache (/?s=… returns an empty 200), so BOTH the catalogue and the metadata
 * come out of plain HTML:
 *   1. catalogue → /sitemap_index.xml → /post-sitemapN.xml → permalinks, then each title page is
 *      fetched (in small pools) for its real metadata. `sourceKey` is the URL slug, not a post id,
 *      because the sitemap only carries permalinks.
 *   2. episodes  → movies only; one playable episode.
 *   3. resolve   → title page `<iframe src="/embed/?link={playerUrl}">`
 *                  → {playerUrl} 302s to backup.ssplayer168.xyz/api/embed/index.php?id={pid}
 *                  → its v5/jw variant page carries the HLS master (master.steamhls88.com/hlsr2/…).
 *      Segments are served as .jpg with a real PNG header in front of the MPEG-TS payload (TS sync at
 *      ~545 bytes) — exactly what [App\Support\HlsSegment] already strips for goseries4k.
 *
 * No live search (the site's ?s= is cached to an empty body), so hd432 can't be matched by the
 * search-based [App\Support\BackupFinder]; it takes part in failover through [App\Models\ContentMirror],
 * which pairs duplicate titles from NetWix's own catalogue instead.
 */
class Hd432Source implements BackupPoolSource, MediaSource, ProvidesPoster, ProvidesSynopsis
{
    public const BASE = 'https://hd432.com';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** Title pages fetched at once while crawling the sitemap — polite, but ~5× faster than serial. */
    private const POOL = 5;

    /** Source category slug → NetWix genre. Country/collection buckets are left out on purpose: they
     *  aren't genres, and ImportService keyword-guesses a genre for anything unmapped. */
    private const GENRE_MAP = [
        'action' => 'แอ็กชัน', 'superhero' => 'แอ็กชัน', 'war' => 'แอ็กชัน', 'martial-arts' => 'แอ็กชัน',
        'adventure' => 'ผจญภัย',
        'comedy' => 'ตลก',
        'drama' => 'ดราม่า', 'biography' => 'ดราม่า', 'family' => 'ดราม่า', 'documentary' => 'ดราม่า',
        'romance' => 'โรแมนติก', 'musical' => 'โรแมนติก', 'music' => 'โรแมนติก',
        'horror' => 'สยองขวัญ',
        'thriller' => 'อาชญากรรม', 'crime' => 'อาชญากรรม', 'mystery' => 'อาชญากรรม', 'mystry' => 'อาชญากรรม',
        'fantasy' => 'แฟนตาซี & ไซไฟ', 'sci-fi' => 'แฟนตาซี & ไซไฟ', 'scifi' => 'แฟนตาซี & ไซไฟ',
        'history' => 'ย้อนยุค', 'western' => 'ย้อนยุค',
    ];

    /** Sitemap entries that aren't titles (the theme lists its own pages in the post sitemap). */
    private const SKIP_SLUGS = ['', 'contact', 'privacy-policy', 'dmca', 'about', 'terms'];

    public function id(): string
    {
        return 'hd432';
    }

    public function displayName(): string
    {
        return 'HD432 (ภาพยนตร์)';
    }

    public function defaultContentType(): string
    {
        return 'movie';
    }

    public function isProgressive(): bool
    {
        return false;   // HLS — streams through the server proxy, no stored preview needed
    }

    public function umbrellaGenre(): ?string
    {
        return null;    // real movies → they belong on /movies, under their own genres
    }

    public function isBackupPool(): bool
    {
        return true;    // own catalogue + own player CDN → a valid failover target
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(45)->retry(2, 400);
    }

    // --------------------------------------------------------- catalogue

    /**
     * Crawl the sitemap index, then each post sitemap, emitting one batch per sitemap (~200 titles) so
     * a timeout still keeps everything already synced. $maxPages caps the number of post sitemaps.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $total = 0;

        foreach (array_slice($this->postSitemaps(), 0, max(1, $maxPages)) as $sitemap) {
            $urls = $this->sitemapUrls($sitemap);
            if ($urls === []) {
                continue;
            }

            $items = $this->titlesFromUrls($urls);
            if ($items === []) {
                continue;
            }

            $onBatch($items);
            $total += count($items);
        }

        return $total;
    }

    /** @return string[] post-sitemap URLs listed by the sitemap index */
    private function postSitemaps(): array
    {
        try {
            $body = $this->http()->get(self::BASE.'/sitemap_index.xml')->body();
        } catch (\Throwable) {
            return [];
        }
        if (! preg_match_all('~<loc>\s*([^<\s]+post-sitemap[^<\s]*)\s*</loc>~i', $body, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /** @return string[] title permalinks in one post sitemap, minus the theme's own pages */
    private function sitemapUrls(string $sitemap): array
    {
        try {
            $body = $this->http()->get($sitemap)->body();
        } catch (\Throwable) {
            return [];
        }
        if (! preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~', $body, $m)) {
            return [];
        }

        $urls = [];
        foreach ($m[1] as $raw) {
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $slug = $this->slugOf($url);
            // Keyed by slug so a permalink listed twice is only crawled once; a slug with a "/" in it
            // is a category/archive URL, not a title.
            if ($slug !== '' && ! in_array($slug, self::SKIP_SLUGS, true) && ! str_contains($slug, '/')) {
                $urls[$slug] = $url;
            }
        }

        return array_values($urls);
    }

    /**
     * Fetch title pages in small concurrent pools and parse each into a RemoteSeries. A page that
     * isn't a single post (the theme's own pages sneak into the sitemap) is dropped.
     *
     * @param  string[]  $urls
     * @return RemoteSeries[]
     */
    private function titlesFromUrls(array $urls): array
    {
        $items = [];

        foreach (array_chunk($urls, self::POOL) as $chunk) {
            try {
                $responses = Http::pool(fn ($pool) => array_map(
                    fn ($url) => $pool->withHeaders([
                        'User-Agent' => self::UA,
                        'Accept-Language' => 'th,en;q=0.8',
                        'Referer' => self::BASE.'/',
                    ])->timeout(30)->get($url),
                    $chunk,
                ));
            } catch (\Throwable) {
                continue;   // whole chunk failed — the next sync picks these up
            }

            foreach ($chunk as $i => $url) {
                $resp = $responses[$i] ?? null;
                if (! $resp instanceof \Illuminate\Http\Client\Response || ! $resp->ok()) {
                    continue;
                }
                $series = $this->parseTitlePage($this->slugOf($url), $resp->body());
                if ($series !== null) {
                    $items[] = $series;
                }
            }
        }

        return $items;
    }

    /** Build a RemoteSeries from a title page, or null when the page isn't a movie post. */
    private function parseTitlePage(string $slug, string $html): ?RemoteSeries
    {
        if ($slug === '' || ! str_contains($html, 'single-post')) {
            return null;   // a WP page (contact/policy), not a title
        }

        $rawTitle = $this->ogTitle($html);
        if ($rawTitle === '') {
            return null;
        }

        $catSlugs = $this->categorySlugs($html);
        $genreNames = [];
        foreach ($catSlugs as $s) {
            if (isset(self::GENRE_MAP[$s])) {
                $genreNames[] = self::GENRE_MAP[$s];
            }
        }

        return new RemoteSeries(
            source: $this->id(),
            sourceKey: $slug,   // the permalink slug — the sitemap has no post ids
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: PosterScraper::fromHtml($html),
            year: $this->parseYear($rawTitle, $html),
            dubType: $this->detectDub($html),
            extra: [
                'slug' => $slug,
                'is_movie' => true,   // hd432 is a movie site — no episode lists anywhere
                'genre_names' => array_values(array_unique($genreNames)),
            ],
        );
    }

    /** Decoded path slug of a permalink ("https://hd432.com/foo-2026-บาร์/" → "foo-2026-บาร์"). */
    private function slugOf(string $url): string
    {
        $path = (string) parse_url(trim($url), PHP_URL_PATH);

        return trim(rawurldecode($path), '/');
    }

    private function ogTitle(string $html): string
    {
        if (! preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]+)"~i', $html, $m)) {
            return preg_match('~<title>(.*?)</title>~si', $html, $t)
                ? trim(html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : '';
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Category slugs from the page's `itemprop="genre"` block — that's the real category list; the
     * tag row below it repeats them, so a unique set is enough.
     *
     * @return string[]
     */
    private function categorySlugs(string $html): array
    {
        if (! preg_match('~itemprop="genre"(.{0,1500}?)</span>~si', $html, $block)) {
            return [];
        }
        if (! preg_match_all('~hd432\.com/([a-z0-9-]+)/~i', $block[1], $m)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** Year from the page's datePublished, else a "(YYYY)" in the title. */
    private function parseYear(string $rawTitle, string $html): ?int
    {
        if (preg_match('~itemprop="datePublished"[^>]*>\s*((?:19|20)\d{2})~i', $html, $m)) {
            return (int) $m[1];
        }
        if (preg_match('~\((19|20)\d{2}\)~', $rawTitle, $m)) {
            return (int) trim($m[0], '()');
        }

        return null;
    }

    /** Strip the trailing SEO tail ("… ดูหนังออนไลน์ HD พากย์ไทย ซับไทย | HD432") off the display title. */
    private function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('~\s*\|\s*HD432.*$~ui', '', $t) ?? $t;
        $t = preg_replace('~\s*(ดูหนังออนไลน์|ดูหนัง|เต็มเรื่อง|พากย์ไทย|ซับไทย|ซับ|พากย์|HD)\s*~u', ' ', $t) ?? $t;
        $t = trim(preg_replace('~\s{2,}~u', ' ', $t) ?? $t);

        return $t !== '' ? $t : trim($raw);
    }

    private function detectDub(string $html): ?string
    {
        if (str_contains($html, 'พากย์ไทย')) {
            return 'thai_dub';
        }
        if (str_contains($html, 'ซับไทย')) {
            return 'thai_sub';
        }

        return null;
    }

    // --------------------------------------------------------- episodes

    /** @return array<int,array{number:int,ref:string}> */
    public function fetchEpisodes(RemoteSeries $series): array
    {
        return [['number' => 1, 'ref' => '1']];   // movie site — always a single video
    }

    // --------------------------------------------------------- synopsis / poster

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        $html = $this->fetchTitlePage($series->sourceKey);

        return $html !== null ? SynopsisScraper::fromHtml($html) : null;
    }

    public function fetchPoster(RemoteSeries $series): ?string
    {
        $html = $this->fetchTitlePage($series->sourceKey);

        return $html !== null ? PosterScraper::fromHtml($html) : null;
    }

    // --------------------------------------------------------- resolve

    /**
     * Title page → player embed → HLS master. $sourceRef is unused (movies), $sourceKey is the slug.
     * Returns null whenever any hop is missing, which is the normal "this title's link died" signal —
     * the caller shows "preparing" and the mirror rotation moves on to the next source.
     */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        $html = $this->fetchTitlePage($sourceKey);
        if ($html === null) {
            return null;
        }

        $playerUrl = $this->playerUrl($html);
        if ($playerUrl === null) {
            return null;
        }

        $master = $this->masterFromPlayer($playerUrl);
        if ($master === null) {
            return null;
        }

        // The CDN gates on the player origin, and the proxy forwards this Referer to the segments too.
        return new RemoteStream(RemoteStream::KIND_HLS, $master, $this->origin($playerUrl).'/');
    }

    /**
     * The player URL out of `<iframe src="https://hd432.com/embed/?link={playerUrl}">`. The link param
     * is NOT url-encoded by the theme (it carries a bare `?id=` of its own), so take everything after
     * `link=` rather than parsing query args.
     */
    private function playerUrl(string $html): ?string
    {
        if (! preg_match('~<iframe[^>]+src="[^"]*?/embed/\?link=([^"]+)"~i', $html, $m)) {
            return null;
        }
        $url = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_starts_with($url, 'http') ? $url : null;
    }

    /**
     * Pull the HLS master out of the player. The landing page (`index.php?id=…`) only links its
     * variant players, so try those in turn — v5/jw/antplayer all embed the SAME master, but a given
     * host doesn't necessarily serve all three. Each 302s to the player's backup host on its own, so
     * the URLs are built from the site's own player link, no redirect bookkeeping needed.
     *
     * What comes back is a MASTER playlist whose audio is a separate rendition, so it's returned as-is:
     * collapsing it to the best video variant would play silently. [StreamController::manifest]
     * re-proxies a master's children.
     */
    private function masterFromPlayer(string $playerUrl): ?string
    {
        $id = preg_match('~[?&]id=([A-Za-z0-9_-]+)~', $playerUrl, $m) ? $m[1] : null;
        if ($id === null) {
            return null;
        }
        $dir = $this->dirname($playerUrl);

        foreach (['v5/index.php', 'jw/main.php', 'antplayer.php'] as $variant) {
            $html = $this->fetchPlayer($dir.'/'.$variant.'?id='.rawurlencode($id));
            if ($html !== null && ($m3u8 = $this->firstM3u8($html)) !== null) {
                return $m3u8;
            }
        }

        // Last resort: the landing page itself, in case the theme ever inlines the stream there.
        $html = $this->fetchPlayer($playerUrl);

        return $html !== null ? $this->firstM3u8($html) : null;
    }

    /** Absolute .m3u8 URL in a player page, or null. */
    private function firstM3u8(string $html): ?string
    {
        return preg_match('~https?://[^\s"\'<>\\\\]+\.m3u8[^\s"\'<>\\\\]*~i', $html, $m) ? $m[0] : null;
    }

    private function fetchPlayer(string $url): ?string
    {
        try {
            $resp = $this->http()->withHeaders(['Referer' => self::BASE.'/'])->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->ok()) {
            return null;
        }
        $body = $resp->body();

        return $body !== '' ? $body : null;
    }

    private function fetchTitlePage(string $slug): ?string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return null;
        }
        try {
            $body = $this->http()->withHeaders(['Referer' => self::BASE.'/'])
                ->get(self::BASE.'/'.rawurlencode($slug).'/')->body();
        } catch (\Throwable) {
            return null;
        }

        return $body !== '' ? $body : null;
    }

    private function origin(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
    }

    private function dirname(string $url): string
    {
        $p = parse_url($url);

        return $this->origin($url).rtrim(str_replace('\\', '/', dirname($p['path'] ?? '/')), '/');
    }
}
