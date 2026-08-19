<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\SearchesPosters;
use App\Services\Import\JsonExtract;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Support\PosterCandidate;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * rongyok.com (โรงหยก) — Chinese short-drama. Three GET endpoints, no auth/captcha/ad-gate:
 *   1. /category?category=all           → embedded `seriesData = [...]` (whole catalogue)
 *   2. /watch/?series_id={id}           → embedded `"episodes":[...]` + episodes_count
 *   3. /watch/{rotating}.php?series_id&ep → {"ok":true,"video_url":"<discord mp4>"}
 *      (the filename rotates — [self::discoverEndpoint] reads the current one out of watch.js;
 *       it was get_video.php, is playseries.php as of 2026-08-19, and needs a Referer)
 *
 * ⚠️ Every request here MUST go through [self::http], which disables ALPN — without that the site
 * answers 403 with its own block page. See that method for the full diagnosis.
 * Videos are plain MP4 on Discord's CDN — signed URLs that expire ~24h, so resolve on demand.
 * PHP port of the Hive Download RongYokClient.
 */
class RongYokSource implements MediaSource, SearchesPosters
{
    public const BASE = 'https://rongyok.com';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function id(): string
    {
        return 'rongyok';
    }

    public function displayName(): string
    {
        return 'โรงหยก (rongyok)';
    }

    public function defaultContentType(): string
    {
        return 'vertical';
    }

    public function isProgressive(): bool
    {
        return true;   // rongyok serves plain Discord-CDN MP4s
    }

    public function umbrellaGenre(): ?string
    {
        return null;
    }

    /**
     * rongyok's HTTP client — the ONE place that carries the ALPN workaround.
     *
     * Since ~2026-08-06 rongyok answered every dynamic path with HTTP 403 and its own branded block
     * page ("ขออภัย คุณถูกบล็อก · โรงหยก"), which silently killed both the nightly catalogue sync and
     * all playback for 2,664 titles. Measured 2026-08-19, the discriminator is the **ALPN extension in
     * the TLS ClientHello**: offering it gets the block, omitting it gets HTTP 200. Everything else was
     * ruled out first — the IP (a residential connection was blocked for curl while Chrome on that very
     * connection loaded the site), the User-Agent (125 and 151 both blocked), the full byte-exact Chrome
     * header set, cookies (a cookies-omitted fetch in Chrome still returned 200), and the HTTP version
     * (h1, h1.0, h2 and h3 all blocked). Static assets were never affected because Cloudflare serves
     * /images/*, .css and .js from its edge cache, so those requests are never evaluated.
     *
     * So this is not a header trick that will rot next week — it changes the TLS handshake itself, which
     * is the thing being fingerprinted. If it ever stops working, the block page carries the site's own
     * LINE (lin.ee/EQP22ad) and Facebook (facebook.com/seriesrongyok) contacts for an appeal.
     */
    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->withOptions(['curl' => [CURLOPT_SSL_ENABLE_ALPN => false]])
            ->timeout(60)->retry(2, 400);
    }

    /** Titles per page of the /category/all/page/N/ grid (fixed by the site — per_page is ignored). */
    private const GRID_PAGE_SIZE = 30;

    /** Ceiling on the sweep so a layout change can't turn it into an endless crawl (93 pages today). */
    private const GRID_MAX_PAGES = 250;

    /** rongyok's robots.txt asks Crawl-delay: 1 — honour it. */
    private const GRID_SLEEP_US = 1_000_000;

    /**
     * Two ways in, cheapest first.
     *
     * The old single-request catalogue is gone: `/category?category=all` used to embed the whole
     * `seriesData = [...]` literal and now serves a 6 KB shell with no data in it at all, which is why
     * this returned 0 titles and the sync died quietly. What replaced it is a server-rendered grid at
     * `/category/all/page/{N}/`, 30 cards a page, 404 past the end (93 pages ≈ 2,783 titles on
     * 2026-08-19).
     *
     * The homepage still carries a `seriesData` array, but only the newest 300 — and it is the ONLY
     * place that still exposes `view_count` and `created_at`, which the grid does not render. So the
     * newest 300 come from there in one request, and the grid supplies everything older.
     *
     * $maxPages is honoured as a number of TITLES, not pages: callers budget it in WP-REST terms where
     * a page is 100 posts (netwix:auto-import asks for "4 pages of newest releases"), and taking it
     * literally against a 30-card grid would quietly shrink that by more than half — the same trap
     * that shrank 24-hdx's RSS window (see [HalimSource::fetchCatalogViaRss]).
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $budget = max(1, $maxPages) * 100;

        $seen = [];
        $total = 0;

        $newest = $this->fetchNewest();
        foreach ($newest as $s) {
            $seen[$s->sourceKey] = true;
        }
        if ($newest !== []) {
            $onBatch($newest);
            $total = count($newest);
        }
        if ($total >= $budget) {
            return $total;
        }

        // The grid is newest-first too, so the pages the homepage already covered are pure repeats.
        // Start just inside that overlap rather than at page 1: walking them cost ten requests and ten
        // seconds during which no batch was emitted, so the admin's progress counter sat still and the
        // "หยุด" button — which is only checked when a batch is emitted — did not respond either.
        // One page of deliberate overlap absorbs anything published between the two requests.
        $from = max(1, (int) floor(count($newest) / self::GRID_PAGE_SIZE));

        for ($page = $from; $page <= self::GRID_MAX_PAGES; $page++) {
            if ($page > 1) {
                usleep(self::GRID_SLEEP_US);
            }
            try {
                $resp = $this->http()->get(self::BASE."/category/all/page/{$page}/");
            } catch (\Throwable) {
                break;   // network trouble — keep whatever is already synced
            }
            if (! $resp->ok()) {
                break;   // 404 = walked off the end
            }

            $cards = $this->parseGrid($resp->body());
            if ($cards === []) {
                break;   // an empty page means the end, or a layout change we must not loop over
            }

            $batch = [];
            foreach ($cards as $s) {
                if (! isset($seen[$s->sourceKey])) {
                    $seen[$s->sourceKey] = true;
                    $batch[] = $s;
                }
            }
            // Emit EVERY page, even one that turned out to be all repeats. The caller's callback is
            // what refreshes the admin's progress and what checks the stop flag ([App\Jobs\
            // SyncCatalogJob]), so staying silent through a run of repeat pages looks exactly like a
            // hung sync and leaves "หยุด" unanswered until the next page with something new on it.
            $onBatch($batch);
            $total += count($batch);

            if ($total >= $budget) {
                break;
            }
        }

        return $total;
    }

    /**
     * The newest ~300 titles from the homepage's `seriesData` literal — one request, and the only
     * source that still carries view_count / created_at.
     *
     * @return RemoteSeries[]
     */
    private function fetchNewest(): array
    {
        try {
            $html = $this->http()->get(self::BASE.'/')->body();
        } catch (\Throwable) {
            return [];
        }
        $json = JsonExtract::catalogArray($html);
        $arr = $json ? json_decode($json, true) : null;
        if (! is_array($arr)) {
            return [];
        }

        $out = [];
        foreach ($arr as $el) {
            if (is_array($el) && ($s = $this->parseSeries($el))) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * Parse one grid page into titles.
     *
     * Each card is flat markup (verified against the live page 2026-08-19):
     *   <div class="movie-card"><a href="/series/8599/">
     *     <div …><img src="/images/poster/รักสวยตามเวลา-พากย์ไทย-2026-8599.webp" alt="รักสวยตามเวลา" …>
     *     <div class="lang-badge dub" …>พากย์ไทย</div></div>
     *     <div class="movie-tag">รักสวยตามเวลา</div></a></div>
     *
     * Split on the card class and match each field separately rather than with one long pattern, so a
     * reordered attribute or an added wrapper costs a field instead of the whole page. The `lang-badge`
     * is worth having on its own: it states dub vs sub outright, which `seriesData` never did — that
     * had to be guessed from the poster filename.
     *
     * @return RemoteSeries[]
     */
    private function parseGrid(string $html): array
    {
        $chunks = preg_split('~<div class="movie-card"~', $html);
        if ($chunks === false || count($chunks) < 2) {
            return [];
        }
        array_shift($chunks);   // everything before the first card

        $out = [];
        foreach ($chunks as $card) {
            if (! preg_match('~href="/series/(\d+)/?"~', $card, $m)) {
                continue;
            }
            $id = $m[1];

            if (! preg_match('~<img[^>]+src="([^"]+)"~i', $card, $mi)) {
                continue;
            }
            $poster = html_entity_decode($mi[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // The theme points a broken cover at its own placeholder — that is not a poster.
            if (str_contains($poster, 'no-image')) {
                $poster = '';
            }

            $title = preg_match('~<div class="movie-tag"[^>]*>([^<]+)<~u', $card, $mt)
                ? trim(html_entity_decode($mt[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : (preg_match('~<img[^>]+alt="([^"]*)"~i', $card, $ma)
                    ? trim(html_entity_decode($ma[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                    : '');
            if ($title === '') {
                continue;
            }

            $badge = preg_match('~class="lang-badge\s+(dub|sub)~i', $card, $mb) ? strtolower($mb[1]) : null;

            $out[] = $this->seriesFromCard($id, $title, $poster, $badge);
        }

        return $out;
    }

    /**
     * Build a RemoteSeries from one grid card. The poster filename is still the best source of the
     * clean title and year (see [self::parseSeries]); the badge overrides its language guess because
     * the site states that outright. view_count is deliberately 0 — the grid does not render it, and
     * [self::fetchNewest] is where a real figure comes from.
     */
    private function seriesFromCard(string $id, string $rawTitle, string $poster, ?string $badge): RemoteSeries
    {
        [$clean, $year, $dub] = $this->fromPosterName($poster);

        if ($badge !== null) {
            $dub = $badge === 'dub' ? 'thai_dub' : 'thai_sub';
        }

        return new RemoteSeries(
            source: 'rongyok',
            sourceKey: $id,
            title: $rawTitle,
            cleanTitle: $clean ?: $this->cleanTitle($rawTitle),
            posterUrl: $this->abs($poster),
            year: $year,
            dubType: $dub ?? $this->detectDub($poster.$rawTitle),
            extra: ['poster_url' => $this->abs($poster)],
        );
    }

    private function parseSeries(array $el): ?RemoteSeries
    {
        if (! isset($el['id'])) {
            return null;
        }
        $id = (string) (int) $el['id'];
        $rawTitle = trim((string) ($el['title'] ?? ''));
        $posterRel = (string) ($el['poster_url'] ?? '');
        $jpgRel = (string) ($el['jpg_url'] ?? '');

        [$clean, $year, $dub] = $this->fromPosterName($posterRel);
        if ($dub === null) {
            $dub = $this->detectDub($posterRel.$rawTitle);
        }
        if (! $clean) {
            $clean = $this->cleanTitle($rawTitle);
        }

        return new RemoteSeries(
            source: 'rongyok',
            sourceKey: $id,
            title: $rawTitle,
            cleanTitle: $clean,
            description: ((string) ($el['description'] ?? '')) ?: null,
            posterUrl: $this->abs($jpgRel !== '' ? $jpgRel : $posterRel),
            year: $year,
            dubType: $dub,
            viewCount: (int) ($el['view_count'] ?? 0),
            extra: ['poster_url' => $this->abs($posterRel)],
        );
    }

    /**
     * Read the clean title, year and language out of a poster filename — still the most reliable
     * source for all three, because the displayed title carries the site's own tags.
     *
     * Two formats live side by side. The original ends in the numeric series id
     * (`…-พากย์ไทย-2026-8599.webp`) and is what all 2,665 of our stored rows use; newer uploads end in
     * an 8-character hex hash instead (`…-พากย์ไทย-2026-71a0287d.webp`), which the old id-only pattern
     * could not match. The language tag is optional too — a title without it still yields a usable
     * clean title and year rather than nothing.
     *
     * @return array{0:?string,1:?int,2:?string} [cleanTitle, year, dubType] — any of them null
     */
    private function fromPosterName(string $posterRel): array
    {
        if ($posterRel === '') {
            return [null, null, null];
        }

        $re = '~poster/(?<title>.+?)'
            .'(?:-(?<type>พากย์ไทย|ซับไทย))?'
            .'-(?<year>\d{4})'
            .'-(?:\d+|[0-9a-f]{6,12})\.~u';

        if (! preg_match($re, $posterRel, $m)) {
            return [null, null, null];
        }

        $dub = match ($m['type'] ?? '') {
            'พากย์ไทย' => 'thai_dub',
            'ซับไทย' => 'thai_sub',
            default => null,
        };

        return [rawurldecode($m['title']), (int) $m['year'], $dub];
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

    /** Strips the trailing "th" language tag the site appends to raw titles. */
    private function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        if (mb_strlen($t) > 2 && str_ends_with(strtolower($t), 'th')) {
            $t = rtrim(mb_substr($t, 0, mb_strlen($t) - 2));
        }

        return $t;
    }

    private function abs(string $rel): ?string
    {
        if ($rel === '') {
            return null;
        }

        return str_starts_with($rel, 'http') ? $rel : self::BASE.'/'.ltrim($rel, '/');
    }

    public function fetchEpisodes(RemoteSeries $series): array
    {
        $html = $this->http()->get(self::BASE.'/watch/', ['series_id' => $series->sourceKey])->body();

        $nums = [];
        if ($epJson = JsonExtract::episodesArray($html)) {
            $arr = json_decode($epJson, true);
            if (is_array($arr)) {
                foreach ($arr as $e) {
                    if (isset($e['episode_number'])) {
                        $nums[] = (int) $e['episode_number'];
                    }
                }
            }
        }
        if (! $nums && preg_match('/"episodes_count"\s*:\s*(\d+)/', $html, $m)) {
            for ($i = 1; $i <= (int) $m[1]; $i++) {
                $nums[] = $i;
            }
        }

        sort($nums);

        return array_map(fn ($n) => ['number' => $n, 'ref' => (string) $n], $nums);
    }

    private const ENDPOINT_CACHE_KEY = 'rongyok:video_endpoint';

    /**
     * Where to look when watch.js can't be read. `get_video.php` is GONE — it answers a 404 page now —
     * so the fallback follows the rotation to the name in use on 2026-08-19. Note this endpoint refuses
     * a request with no Referer (`{"ok":false,"error":"origin"}`), which [self::callResolve] sends.
     */
    private const ENDPOINT_FALLBACK = 'playseries.php';

    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        // rongyok rotates the resolver endpoint filename to deter scrapers; the OLD get_video.php
        // now returns already-expired Discord URLs. Use the cached endpoint first.
        $cached = Cache::get(self::ENDPOINT_CACHE_KEY);
        if ($cached) {
            $endpoint = $cached;
        } else {
            $endpoint = $this->discoverEndpoint();
            Cache::put(self::ENDPOINT_CACHE_KEY, $endpoint, now()->addHour());
        }

        if ($stream = $this->callResolve($endpoint, $sourceKey, $sourceRef)) {
            return $stream;
        }

        // Only the CACHED endpoint can be stale-due-to-rotation (a just-discovered one is current),
        // so re-discover once and retry only if the filename actually changed.
        if ($cached !== null) {
            $fresh = $this->discoverEndpoint();
            Cache::put(self::ENDPOINT_CACHE_KEY, $fresh, now()->addHour());
            if ($fresh !== $endpoint && ($stream = $this->callResolve($fresh, $sourceKey, $sourceRef))) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * Read the current (rotating) resolver endpoint filename (e.g. "xq7bza9k.php") from
     * /watch/watch.js. Tolerates JSON/bundler slash-escaping and other query params appearing before
     * series_id; falls back to the legacy get_video.php if it can't be found.
     */
    private function discoverEndpoint(): string
    {
        try {
            $js = str_replace('\\/', '/', $this->http()->get(self::BASE.'/watch/watch.js')->body());
            // e.g.  fetch(`/watch/xq7bza9k.php?series_id=...`)  or  `...php?ep=1&series_id=...`
            if (preg_match('~/watch/([a-z0-9_]{4,})\.php\?[^"\'`\s]*series_id~i', $js, $m)) {
                return $m[1].'.php';
            }
        } catch (\Throwable) {
            // fall through to the legacy endpoint
        }

        return self::ENDPOINT_FALLBACK;
    }

    private function callResolve(string $endpoint, string $sourceKey, string $sourceRef): ?RemoteStream
    {
        try {
            $resp = $this->http()->withHeaders([
                'Referer' => self::BASE."/watch/?series_id={$sourceKey}&ep={$sourceRef}",
                'X-Requested-With' => 'XMLHttpRequest',
            ])->get(self::BASE."/watch/{$endpoint}", ['series_id' => $sourceKey, 'ep' => $sourceRef]);
        } catch (\Throwable) {
            return null;
        }

        if (! $resp->ok()) {
            return null;
        }
        $data = $resp->json();
        if (! is_array($data)) {
            return null;
        }
        $ok = $data['ok'] ?? false;
        if (! ($ok === true || $ok === 'true')) {
            return null;
        }
        $url = $data['video_url'] ?? null;

        // A stale endpoint hands back an already-expired signature — reject it so the caller
        // re-discovers the rotated endpoint instead of playing a dead URL.
        if (! is_string($url) || $url === '' || ! self::urlIsFresh($url)) {
            return null;
        }

        return new RemoteStream(RemoteStream::KIND_MP4, $url);
    }

    /** Discord signed URLs carry ?ex=<hex unix seconds>; a past ex means the URL is already dead. */
    public static function urlIsFresh(string $url): bool
    {
        if (! preg_match('~[?&]ex=([0-9a-f]+)~i', $url, $m)) {
            return true;   // no ex param → can't tell, assume usable
        }

        return hexdec($m[1]) > time() + 60;   // small clock-skew buffer
    }

    /**
     * Look a title up by NAME on rongyok (see [SearchesPosters]).
     *
     * rongyok is not WordPress and has no search route to call, so the search is a local scan over the
     * newest titles, cached — one lookup and forty lookups should cost the same single request.
     *
     * Deliberately scoped to the NEWEST titles rather than the whole catalogue. The full sweep is 93
     * paginated requests (see [self::fetchCatalog]) which is far too heavy to run behind an admin
     * clicking "หาปก", and it would be redundant: everything already synced is in `source_titles`, and
     * [App\Support\PosterSearch] searches that mirror first without touching the network at all. What
     * the mirror CANNOT know about is a title added since the last sync — which is exactly what this
     * covers.
     *
     * @return PosterCandidate[]
     */
    public function searchPosters(string $title, int $limit = 8): array
    {
        $needle = mb_strtolower(trim($title), 'UTF-8');
        if ($needle === '') {
            return [];
        }

        $out = [];
        foreach ($this->catalogueIndex() as [$name, $poster]) {
            // Loose containment either way — ranking in [App\Support\PosterSearch] decides for real.
            $hay = mb_strtolower($name, 'UTF-8');
            if (! str_contains($hay, $needle) && ! str_contains($needle, $hay)) {
                continue;
            }
            $out[] = new PosterCandidate(title: $name, image: $poster, page: self::BASE.'/watch/');
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * [title, posterUrl] for every title in the catalogue, cached.
     *
     * Trimmed to the two fields a cover search needs before caching — the raw page is megabytes of
     * descriptions and episode counts, and the cache store here is the database.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private function catalogueIndex(): array
    {
        // v2 key: the old one was filled from /category?category=all, which the site turned into an
        // empty shell — a cached [] from that page would otherwise keep answering "not found" for half
        // an hour after this fix shipped.
        return Cache::remember('rongyok:poster-index:v2', now()->addMinutes(30), function (): array {
            $index = [];
            foreach ($this->fetchNewest() as $s) {
                $name = trim($s->title);
                $poster = (string) $s->posterUrl;
                if ($name !== '' && $poster !== '') {
                    $index[] = [$name, $poster];
                }
            }

            return $index;
        });
    }
}
