<?php

namespace App\Services\Import\Sources;

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
 * goseries4k.com (ซีรี่ย์ต่างประเทศ) — WordPress ("seed" theme + Foxy player) site of Thai-dubbed
 * foreign SERIES (~3,300 titles, mostly Chinese/Korean drama). Reverse-engineered 2026-07-11 (see
 * [[goseries4k.com — site API map …]]). NOT a Halim site — the catalogue is WP REST like the Halim
 * sources, but the player is different, so it gets its own class. Streams are HLS played through
 * NetWix's server-side proxy ([App\Http\Controllers\StreamController]) — the same KIND_HLS path as
 * the anime108 / 24-hdx sources, no new player infra.
 *
 * Chain (all confirmed server-side, plain GETs — no auth / nonce / admin-ajax):
 *   1. catalogue → GET /wp-json/wp/v2/posts (100/page) + /media posters + /categories.
 *      Only SERIES-LANDING posts are in WP REST (the per-episode posts are not), so the ~3,300 = real
 *      titles, not inflated by episodes. Categories are country/year buckets (จีน/เกาหลี/…), NOT genres.
 *   2. episodes  → GET /?p={seriesId} → <button class="mp-ep-btn" data-id="{EPISODE_POST_ID}">EP N</button>
 *      in on-screen order. No episode list (one-shot) → play the landing post's own embed as ep 1.
 *   3. resolve   → GET /?p={episodePostId} → <iframe src="https://torbo007.com/embed/{32hex}">
 *      → HLS manifest https://torbo007.com/api/stream/{32hex}/index.m3u8  (Referer: torbo007.com REQUIRED,
 *        403 without). Segments live on tiktokcdn as fake PNGs (real header + TS at offset 252) — the
 *        proxy strips them to the TS sync byte via [App\Support\HlsSegment].
 *
 * Old titles decay: torbo007 purges streams for long-idle series, so resolveByRef verifies the manifest
 * is live and returns null (→ "preparing" + backup fallback) when it's gone.
 */
class Goseries4kSource implements MediaSource, ProvidesPoster, ProvidesSynopsis
{
    public const BASE = 'https://goseries4k.com';

    /** torbo007 gates its manifest on this Referer (403 without it); the tiktokcdn segments are open. */
    private const PLAYER_ORIGIN = 'https://torbo007.com';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function id(): string
    {
        return 'goseries4k';
    }

    public function displayName(): string
    {
        return 'GoSeries4K (ซีรี่ย์ต่างประเทศ)';
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
        return null;    // real foreign series → show on /series; genre is keyword-guessed on import
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(60)->retry(2, 400);
    }

    // --------------------------------------------------------- catalogue

    /**
     * WP REST catalogue, 100 posts/page, emitting per page so a timeout keeps the earlier pages. Only
     * series-landing posts come back (episode posts aren't exposed here), so each item is a real title.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $total = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $resp = $this->http()->get(self::BASE.'/wp-json/wp/v2/posts', [
                    'per_page' => 100,
                    'page' => $page,
                    '_fields' => 'id,slug,title,featured_media,categories,date',
                ]);
            } catch (\Throwable) {
                break;
            }
            if (! $resp->ok()) {
                break;   // 400 = past the last page
            }
            $posts = $resp->json();
            if (! is_array($posts) || $posts === []) {
                break;
            }

            $items = $this->parsePosts($posts);
            $onBatch($items);
            $total += count($items);

            if (count($posts) < 100) {
                break;   // last page
            }
        }

        return $total;
    }

    /**
     * Parse a page of WP REST posts into RemoteSeries, then attach posters in one /media batch (the
     * posts endpoint only returns the featured_media id, not its URL — same as the Halim sources).
     *
     * @param  array<int,mixed>  $posts
     * @return RemoteSeries[]
     */
    private function parsePosts(array $posts): array
    {
        $items = [];
        $mediaIds = [];
        foreach ($posts as $el) {
            if (is_array($el) && ($s = $this->parsePost($el)) !== null) {
                $items[] = $s;
                if (! empty($s->extra['media_id'])) {
                    $mediaIds[] = $s->extra['media_id'];
                }
            }
        }

        if ($mediaIds) {
            $posters = $this->fetchPosters(array_values(array_unique($mediaIds)));
            foreach ($items as $s) {
                $mid = $s->extra['media_id'] ?? 0;
                if ($mid && isset($posters[$mid])) {
                    $s->posterUrl = $posters[$mid];
                }
            }
        }

        return $items;
    }

    /** @param array<string,mixed> $el */
    private function parsePost(array $el): ?RemoteSeries
    {
        $id = (int) ($el['id'] ?? 0);
        $slug = (string) ($el['slug'] ?? '');
        if ($id === 0) {
            return null;
        }
        $rawTitle = isset($el['title']['rendered'])
            ? trim(html_entity_decode((string) $el['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : $slug;

        return new RemoteSeries(
            source: $this->id(),
            sourceKey: (string) $id,   // series-landing WP post id — resolves episodes + stream
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: null,
            year: $this->parseYear($rawTitle, (string) ($el['date'] ?? '')),
            dubType: $this->detectDub($rawTitle),
            extra: [
                'slug' => $slug,
                'media_id' => (int) ($el['featured_media'] ?? 0),
                'is_movie' => false,   // goseries4k is series-only
                'genre_names' => [],   // country categories aren't genres → ImportService keyword-guesses
            ],
        );
    }

    /** @param int[] $mediaIds  @return array<int,string> */
    private function fetchPosters(array $mediaIds): array
    {
        $map = [];
        try {
            $json = $this->http()->get(self::BASE.'/wp-json/wp/v2/media', [
                'include' => implode(',', $mediaIds),
                'per_page' => 100,
                '_fields' => 'id,source_url',
            ])->json();
            if (is_array($json)) {
                foreach ($json as $el) {
                    if (isset($el['id'], $el['source_url'])) {
                        $map[(int) $el['id']] = (string) $el['source_url'];
                    }
                }
            }
        } catch (\Throwable) {
            // posters are best-effort — a title just falls back to its gradient placeholder
        }

        return $map;
    }

    /** Strip the "| พากย์ไทย" site tag + the "ตอนที่1-16 (จบ)" episode-range suffix → clean series name. */
    private function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('~\s*\|\s.*$~u', '', $t) ?? $t;                          // drop "| พากย์ไทย / | goseries4k …"
        $t = preg_replace('~\s*(ตอนที่|ตอนล่าสุด|EP\.?\s*\d).*$~ui', '', $t) ?? $t;  // drop episode-range / latest-ep suffix
        $t = preg_replace('~\s*(\(จบ\)|\[จบ\]|พากย์ไทย|ซับไทย|ซับ|พากย์)\s*$~ui', '', trim($t)) ?? $t;

        return trim($t) !== '' ? trim($t) : $raw;
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

    /** Year from the title's "(YYYY)" (the site puts it there), else the post date. */
    private function parseYear(string $rawTitle, string $date): ?int
    {
        if (preg_match('~\((19|20)\d{2}\)~', $rawTitle, $m)) {
            return (int) trim($m[0], '()');
        }
        if (preg_match('~(20\d{2})~', $date, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    // --------------------------------------------------------- episodes

    /**
     * Episodes = the .mp-ep-btn buttons on the series-landing page, whose data-id is each episode's own
     * WP post id. In on-screen order (EP1..EPn) — the ids are NOT sorted, so keep document order. A
     * title with no episode list (one-shot) exposes one episode that resolves the landing's own embed.
     *
     * @return array<int,array{number:int,ref:string}>
     */
    public function fetchEpisodes(RemoteSeries $series): array
    {
        $html = $this->fetchPost($series->sourceKey);
        if ($html === null) {
            return [['number' => 1, 'ref' => $series->sourceKey]];
        }

        $ids = $this->episodePostIds($html);
        if ($ids === []) {
            return [['number' => 1, 'ref' => $series->sourceKey]];
        }

        $eps = [];
        foreach ($ids as $i => $pid) {
            $eps[] = ['number' => $i + 1, 'ref' => $pid];
        }

        return $eps;
    }

    /**
     * Episode-post ids from a series-landing page's `.mp-ep-btn` buttons, in document order, de-duped
     * (the list can be mirrored for a mobile layout).
     *
     * @return string[]
     */
    private function episodePostIds(string $html): array
    {
        if (! preg_match_all('~class="[^"]*\bmp-ep-btn\b[^"]*"[^>]*\bdata-id="(\d+)"~i', $html, $m)) {
            return [];
        }
        $seen = [];
        $ids = [];
        foreach ($m[1] as $pid) {
            if (! isset($seen[$pid])) {
                $seen[$pid] = true;
                $ids[] = $pid;
            }
        }

        return $ids;
    }

    // --------------------------------------------------------- synopsis

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        $html = $this->fetchPost($series->sourceKey);

        return $html !== null ? SynopsisScraper::fromHtml($html) : null;
    }

    /** Re-fetch a fresh poster from the series-landing page's og:image (heals a dead hotlink). */
    public function fetchPoster(RemoteSeries $series): ?string
    {
        $html = $this->fetchPost($series->sourceKey);

        return $html !== null ? PosterScraper::fromHtml($html) : null;
    }

    // --------------------------------------------------------- resolve

    /**
     * Resolve one episode's HLS stream. $sourceRef is an episode POST id (or, for a one-shot, the series
     * id). The episode page carries one torbo007 embed whose 32-hex id IS the manifest key.
     */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        $postId = $sourceRef !== '' ? $sourceRef : $sourceKey;

        $html = $this->fetchPost($postId);
        if ($html === null) {
            return null;
        }
        if (! preg_match('~torbo007\.com/+embed/+([0-9a-f]{32})~i', $html, $m)) {
            return null;   // no player embed on the page (episode removed) → caller shows "preparing"
        }

        $manifest = self::PLAYER_ORIGIN.'/api/stream/'.$m[1].'/index.m3u8';
        if (! $this->manifestIsLive($manifest)) {
            return null;   // torbo purged this stream (common for old titles) → not-ready + backup fallback
        }

        // Referer is REQUIRED for the manifest; the proxy also forwards it to segments (harmless there).
        return new RemoteStream(RemoteStream::KIND_HLS, $manifest, self::PLAYER_ORIGIN.'/');
    }

    /** True if the torbo manifest returns a real HLS media playlist (needs the torbo Referer). */
    private function manifestIsLive(string $manifest): bool
    {
        try {
            $resp = $this->http()->withHeaders(['Referer' => self::PLAYER_ORIGIN.'/'])->get($manifest);
        } catch (\Throwable) {
            return false;
        }

        return $resp->ok() && str_contains($resp->body(), '#EXTINF');
    }

    /**
     * GET a post by id via `/?p={id}` (Guzzle follows the 301 to the /watch/{slug} permalink for
     * episode posts) with the site Referer. Returns the HTML, or null on failure / empty body.
     */
    private function fetchPost(string $postId): ?string
    {
        try {
            $body = $this->http()->withHeaders(['Referer' => self::BASE.'/'])
                ->get(self::BASE.'/', ['p' => $postId])->body();
        } catch (\Throwable) {
            return null;
        }

        return $body !== '' ? $body : null;
    }
}
