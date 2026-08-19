<?php

namespace App\Services\Import\Sources;

use App\Models\SourceTitle;
use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\Contracts\SearchesPosters;
use App\Services\Import\GetPlayerStream;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Support\PosterCandidate;
use App\Support\SiteSearchScraper;
use App\Support\SynopsisScraper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * wow-drama.com — WordPress site (theme "wowdrama" + "miru-player" plugin) hosting full-length
 * CN/KR/JP series. Verified flow:
 *   1. catalog  → Yoast /sitemap_index.xml → /post-sitemap*.xml (slugs + featured image)
 *   2. episodes → GET /{slug}/  (parse the .mp-ep-btn buttons → wp post ids, in order)
 *   3. resolve  → scrape `miruNonce` off /{slug}/, then POST /wp-admin/admin-ajax.php
 *                 action=miru_get_api_player_url&ep_id={id}&server_idx=0&nonce={nonce}
 *                 → getplay-cdn embed hash → signed HLS via [App\Services\Import\GetPlayerStream]
 * PHP port of the Hive Download WowDramaClient.
 *
 * Step 1 used to be WP REST (/wp-json/wp/v2/posts + /media). The site locked its REST API behind
 * auth around 2026-07-11 — EVERY route now answers 401 `rest_login_required`, including the
 * ?rest_route= and /index.php?rest_route= entry points and _envelope=1 (which returns HTTP 200
 * wrapping an inner 401). All verified; there is no unauthenticated bypass. The nightly sync had
 * been dying on that 401 every night since, unnoticed, because it only ever hit laravel.log.
 *
 * Steps 2 and 3 were never affected — the episode buttons and admin-ajax still answer 200, so
 * playback of everything already imported kept working the whole time.
 */
class WowDramaSource implements MediaSource, ProvidesSynopsis, SearchesPosters
{
    public const BASE = 'https://wow-drama.com';
    public const GETPLAY = 'https://getplay-cdn.com';
    /** Host that embeds the getplay player — it is signed into the playback token. */
    private const PARENT = 'wow-drama.com';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** Yoast sitemap index — the catalogue listing that replaced WP REST. */
    private const SITEMAP_INDEX = '/sitemap_index.xml';

    /** Hand titles to sync() in modest batches so the admin progress poll + stop button stay live. */
    private const BATCH = 50;

    /** Politeness gap between detail-page fetches. Only never-before-seen slugs pay it. */
    private const DETAIL_SLEEP_US = 200_000;

    public function id(): string
    {
        return 'wowdrama';
    }

    public function displayName(): string
    {
        return 'wow-drama';
    }

    public function defaultContentType(): string
    {
        return 'series';
    }

    public function isProgressive(): bool
    {
        return false;   // HLS — streams through the server proxy, no stored preview needed
    }

    public function umbrellaGenre(): ?string
    {
        return null;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(60)->retry(2, 400);
    }

    /**
     * Walk the Yoast post sitemaps. $maxPages caps how many child sitemap FILES we read (there are
     * three, ~780 posts each) — the old per_page=100 REST paging is gone, so callers passing 30/100
     * still cover the whole catalogue.
     *
     * The sitemap gives a slug and a featured image but no title, so a slug we have never seen costs
     * one detail-page fetch; a slug already in `source_titles` reuses the stored title/poster and
     * costs nothing. That keeps a nightly run proportional to the number of NEW posts instead of
     * re-fetching ~2,300 pages, which is why this reads our own table — the alternative is either
     * hammering the source site every night or dropping every Thai title down to its slug.
     *
     * The sitemap is not exhaustive (it lists ~2,340 posts while we hold ~2,490, so it omits some
     * older ones). That is harmless: sync() only ever upserts and never prunes, so titles missing
     * from the sitemap keep their rows and stay imported.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $sitemaps = $this->postSitemaps();
        if ($sitemaps === []) {
            return 0;
        }

        // `description` is carried along, not just read for the title: sync() writes every column it
        // is handed, and a catalogue feed has no synopsis — so re-syncing would null out the
        // synopses ImportService::fillSynopsis had scraped into these rows one by one.
        $known = SourceTitle::where('source', $this->id())
            ->get(['source_key', 'title', 'description', 'poster_url'])
            ->keyBy('source_key');

        $total = 0;
        $batch = [];

        foreach (array_slice($sitemaps, 0, max(1, $maxPages)) as $sitemapUrl) {
            if (($xml = $this->body($sitemapUrl)) === null) {
                continue;
            }

            foreach ($this->sitemapEntries($xml) as $slug => $image) {
                if (($item = $this->series($slug, $image, $known->get($slug))) === null) {
                    continue;
                }
                $batch[] = $item;

                if (count($batch) >= self::BATCH) {
                    $onBatch($batch);
                    $total += count($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            $onBatch($batch);
            $total += count($batch);
        }

        return $total;
    }

    /** Child post sitemaps from the Yoast index (post-sitemap.xml, post-sitemap2.xml, …). */
    private function postSitemaps(): array
    {
        if (($xml = $this->body(self::BASE.self::SITEMAP_INDEX)) === null) {
            return [];
        }
        preg_match_all(
            '~<loc>\s*('.preg_quote(self::BASE, '~').'/post-sitemap\d*\.xml)\s*</loc>~i',
            $xml, $m
        );

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * slug => featured image, from one child sitemap. Yoast lists the featured image first, which
     * matches the post's og:image (verified against stored posters from the REST era). Keyed by slug
     * so a post repeated across files collapses to one entry.
     *
     * @return array<string,?string>
     */
    private function sitemapEntries(string $xml): array
    {
        $out = [];

        foreach (explode('</url>', $xml) as $block) {
            if (! preg_match('~<loc>\s*'.preg_quote(self::BASE, '~').'/([^<\s]+?)/?\s*</loc>~i', $block, $m)) {
                continue;
            }
            // Post permalinks are a single path segment; anything deeper is a category/page URL.
            // NB: the slug stays percent-encoded exactly as it appears — that is the form WP's REST
            // `slug` field returned and the form the episode/synopsis fetchers build URLs from.
            $slug = $m[1];
            if ($slug === '' || str_contains($slug, '/')) {
                continue;
            }

            $out[$slug] = preg_match('~<image:loc>\s*([^<\s]+)\s*</image:loc>~i', $block, $i) ? $i[1] : null;
        }

        return $out;
    }

    private function series(string $slug, ?string $image, ?SourceTitle $known): ?RemoteSeries
    {
        $rawTitle = trim((string) $known?->title);
        $poster = $known?->poster_url ?: $image;

        if ($rawTitle === '') {
            [$fetched, $detailPoster] = $this->fetchDetail($slug);
            if ($fetched === null) {
                return null;   // deleted or unreachable post — skip it, sync() never prunes
            }
            $rawTitle = $fetched;
            $poster = $detailPoster ?: $image;
        }

        return new RemoteSeries(
            source: 'wowdrama',
            sourceKey: $slug,
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            description: $known?->description,
            posterUrl: $poster,
            year: $this->parseYear($rawTitle, $slug),
            dubType: $this->detectDub($rawTitle),
            extra: ['slug' => $slug],
        );
    }

    /**
     * One request for a slug we have never seen. The post page carries the post title in <title> and
     * the featured image in og:image — both verified byte-identical to what the REST era stored for
     * title.rendered and the /media source_url, so cleanTitle(), detectDub() and the year regex keep
     * behaving exactly as they did. (The page's wp-post-image tags are NOT usable: the first ones
     * belong to the "related posts" rail, not to this post.)
     *
     * @return array{0:?string,1:?string} [raw title, poster]
     */
    private function fetchDetail(string $slug): array
    {
        $html = $this->body(self::BASE.'/'.$slug.'/');
        usleep(self::DETAIL_SLEEP_US);
        if ($html === null) {
            return [null, null];
        }

        $title = null;
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $poster = null;
        if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
            $poster = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return [($title ?? '') === '' ? null : $title, $poster];
    }

    private function parseYear(string $rawTitle, string $slug): ?int
    {
        if (preg_match('~(?:\((\d{4})\)|-(\d{4})(?:$|[/\-]))~', $rawTitle.' '.$slug, $ym)) {
            return (int) ($ym[1] ?: $ym[2]);
        }

        return null;
    }

    private function body(string $url): ?string
    {
        try {
            $resp = $this->http()->get($url);
        } catch (\Throwable) {
            return null;
        }

        return $resp->ok() ? $resp->body() : null;
    }

    private function detectDub(string $t): ?string
    {
        if (str_contains($t, 'พากย์ไทย')) {
            return 'thai_dub';
        }
        if (str_contains($t, 'ซับไทย')) {
            return 'thai_sub';
        }

        return null;
    }

    private function cleanTitle(string $raw): string
    {
        // "ละคร" was never in the prefix list, so titles posted as "ดูละคร …" kept the whole prefix.
        // The separator strip matters more than it looks: the source writes "ดูซีรี่ย์จีน | (1985) …"
        // and "ดูละคร >> …", and once the prefix goes the leftover punctuation LEADS the title —
        // 306 titles are live on the site right now reading "| สยบชะตาท้าลิขิตรัก …". Everything else
        // here is left alone deliberately; widening it further would churn ~2,500 display titles.
        $t = preg_replace('~^\s*ดู(ซีรี่ส์|ซีรี่ย์|ซีรีส์|ละคร|หนัง)(จีน|เกาหลี|ญี่ปุ่น|ไทย|ฝรั่ง)?\s*~u', '', $raw) ?? $raw;
        $t = preg_replace('~^\s*(?:>>|»|\|)\s*~u', '', $t) ?? $t;
        for ($i = 0; $i < 3; $i++) {
            $next = trim(preg_replace('~\s*(เต็มเรื่อง|จบเรื่อง|ครบทุกตอน|ทุกตอน|พากย์ไทย|ซับไทย|ซับ|พากย์|HD|ครบ)\s*$~u', '', $t) ?? $t);
            if ($next === $t) {
                break;
            }
            $t = $next;
        }

        return trim($t) === '' ? $raw : $t;
    }

    public function fetchEpisodes(RemoteSeries $series): array
    {
        $html = $this->http()->get(self::BASE.'/'.$series->sourceKey.'/')->body();

        $out = [];
        // Whitespace-tolerant on purpose. The theme emits a NEWLINE between the tag name and its
        // attributes — "<button\nclass=\"mp-ep-btn mp-active\" data-id=\"91643\">" — so the old
        // literal '<button class="mp-ep-btn' stopped matching anything the day the site's markup
        // changed. It failed silently: zero episodes for EVERY wow-drama title, so new imports
        // arrived as empty shells and netwix:refresh-episodes found nothing to add.
        if (preg_match_all('~<button[^>]*class="[^"]*mp-ep-btn[^"]*"[^>]*data-id="(\d+)"~s', $html, $m)) {
            foreach ($m[1] as $i => $postId) {
                $out[] = ['number' => $i + 1, 'ref' => (string) $postId];
            }
        }

        return $out;
    }

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        $slug = trim((string) ($series->extra['slug'] ?? ''), '/');
        if ($slug === '') {
            return null;
        }
        try {
            $html = $this->http()->get(self::BASE.'/'.$slug.'/')->body();
        } catch (\Throwable) {
            return null;
        }

        return SynopsisScraper::fromHtml($html);
    }

    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        $embedUrl = $this->playerUrl($sourceKey, $sourceRef);
        if ($embedUrl === null || ! preg_match('~getplay-cdn\.com/embed/([a-f0-9]{16,})~', $embedUrl, $m)) {
            return null;
        }
        $embed = self::GETPLAY.'/embed/'.$m[1];

        // getplay-cdn is the same product as goseries4k's torbo007, and both now require the signed
        // handshake in [App\Services\Import\GetPlayerStream]. Note the manifest is keyed on the
        // stream id that handshake RETURNS, not on the embed hash this used to put in the path — that
        // form now answers 403 Invalid Secure ID.
        $m3u8 = GetPlayerStream::manifest($this->http(), self::GETPLAY, $m[1], self::PARENT);
        if ($m3u8 === null) {
            return null;   // no token → no stream; caller shows "preparing" / rotates to a backup link
        }

        return new RemoteStream(RemoteStream::KIND_HLS, $m3u8, $embed);
    }

    /**
     * Ask wow-drama for an episode's embed URL.
     *
     * Their theme changed under us around 2026-08: `admin-ajax action=miru_custom_player` is gone —
     * WordPress answers an unregistered action with a bare `0` and HTTP 400, which our retry() turned
     * into a thrown RequestException, which netwix:source-canary then read as "our own network
     * wobbled" and declined to render any verdict on. So the source looked neither up nor down.
     *
     * The replacement is `miru_get_api_player_url`, it takes the episode post id plus a server index,
     * and it is nonce-gated. The nonce is printed into the series page as `var miruNonce = "…"` and
     * needs no cookie, so one page fetch per resolve is enough. The page also carries the empty
     * `epData` map — episodes no longer ship their embed inline at all, which is why nothing can be
     * scraped straight out of the HTML any more.
     */
    private function playerUrl(string $sourceKey, string $sourceRef): ?string
    {
        try {
            $page = $this->http()->withHeaders(['Referer' => self::BASE.'/'])
                ->get(self::BASE.'/'.$sourceKey.'/')->body();
        } catch (\Throwable) {
            return null;
        }
        if (! preg_match('~miruNonce\s*=\s*[\'"]([a-f0-9]+)[\'"]~i', $page, $n)) {
            return null;
        }

        try {
            $resp = $this->http()->asForm()->withHeaders([
                'Referer' => self::BASE.'/'.$sourceKey.'/',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->post(self::BASE.'/wp-admin/admin-ajax.php', [
                'action' => 'miru_get_api_player_url',
                'ep_id' => $sourceRef,
                'server_idx' => 0,
                'nonce' => $n[1],
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->ok()) {
            return null;
        }

        $j = $resp->json();

        return is_array($j) && ! empty($j['success']) && filled($j['data']['url'] ?? null)
            ? (string) $j['data']['url']
            : null;
    }

    /**
     * Look a title up by NAME on this site (see [SearchesPosters]) — WordPress's own "?s=" search,
     * parsed by [SiteSearchScraper]. Used to find a cover for a title whose own source can no longer
     * answer for it (a dead site, or a row imported with no poster URL at all).
     *
     * @return PosterCandidate[]
     */
    public function searchPosters(string $title, int $limit = 8): array
    {
        return SiteSearchScraper::searchWordPress($this->http(), self::BASE, $title, $limit);
    }
}
