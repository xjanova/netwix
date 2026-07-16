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
 * Config-driven source for any WordPress + Halim-theme site (24-hdx, anime108, …). All per-site
 * differences live in the [HalimSiteConfig] this is constructed with, so adding a site = one config
 * in [HalimSites], not a subclass. Verified flow (2026-07):
 *   1. catalogue → WP REST /wp-json/wp/v2/posts (100/page) + /media posters + /categories genres
 *   2. episodes  → movie = single video; series = parse the detail-page episode <select>/links
 *   3. resolve   → POST {apiUrl} (action=halim_ajax_player, postid, episode, server, lang)
 *                  → iframe {playerHost}/index_th.php?id={hash}
 *                  → HLS master newplaylist/{hash}/{hash}.m3u8 → pick best-bitrate variant
 *
 * Streams are HLS, played through NetWix's server-side proxy ([StreamController]).
 */
class HalimSource implements BackupPoolSource, MediaSource, ProvidesPoster, ProvidesSynopsis
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct(private HalimSiteConfig $config) {}

    public function config(): HalimSiteConfig
    {
        return $this->config;
    }

    public function id(): string
    {
        return $this->config->id;
    }

    public function displayName(): string
    {
        return $this->config->displayName;
    }

    public function defaultContentType(): string
    {
        return $this->config->defaultContentType;
    }

    public function isProgressive(): bool
    {
        return false;   // Halim = HLS — streams through the server proxy, no stored preview needed
    }

    public function umbrellaGenre(): ?string
    {
        return $this->config->umbrellaGenre;
    }

    /** Eligible to serve as a backup stream for another site's un-playable title. */
    public function isBackupPool(): bool
    {
        return $this->config->backupPool;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(60)->retry(2, 400);
    }

    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $cats = $this->fetchCategoryMap();   // id => ['name','slug'], best-effort
        $total = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $resp = $this->http()->get($this->config->base.'/wp-json/wp/v2/posts', [
                'per_page' => 100,
                'page' => $page,
                '_fields' => 'id,slug,title,featured_media,categories,date',
            ]);
            if (! $resp->ok()) {
                break; // 400 = past the last page
            }
            $posts = $resp->json();
            if (! is_array($posts) || $posts === []) {
                break;
            }

            $items = $this->parsePosts($posts, $cats);
            $onBatch($items);
            $total += count($items);

            if (count($posts) < 100) {
                break; // last page
            }
        }

        return $total;
    }

    /**
     * Full-text search this site's catalogue (WP REST ?search=) → RemoteSeries[] with posters filled.
     * Used by [App\Support\BackupFinder] to locate a title on a backup site. Best-effort: any failure
     * returns [].
     *
     * @return RemoteSeries[]
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        try {
            $resp = $this->http()->get($this->config->base.'/wp-json/wp/v2/posts', [
                'search' => $query,
                'per_page' => max(1, min(20, $limit)),
                '_fields' => 'id,slug,title,featured_media,categories,date',
            ]);
        } catch (\Throwable) {
            return [];
        }
        if (! $resp->ok()) {
            return [];
        }
        $posts = $resp->json();
        if (! is_array($posts) || $posts === []) {
            return [];
        }

        return $this->parsePosts($posts, $this->fetchCategoryMap());
    }

    /**
     * Parse a page of WP REST posts into RemoteSeries, then attach posters in one /media batch.
     *
     * @param  array<int,mixed>  $posts
     * @param  array<int,array{name:string,slug:string}>  $cats
     * @return RemoteSeries[]
     */
    private function parsePosts(array $posts, array $cats): array
    {
        $items = [];
        $mediaIds = [];
        foreach ($posts as $el) {
            if (is_array($el) && ($s = $this->parsePost($el, $cats)) !== null) {
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

    /** @param array<int,array{name:string,slug:string}> $cats */
    private function parsePost(array $el, array $cats): ?RemoteSeries
    {
        $id = (int) ($el['id'] ?? 0);
        $slug = (string) ($el['slug'] ?? '');
        if ($id === 0 || $slug === '') {
            return null;
        }
        $rawTitle = isset($el['title']['rendered'])
            ? trim(html_entity_decode((string) $el['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : $slug;

        $catIds = array_map('intval', (array) ($el['categories'] ?? []));
        $catNames = array_filter(array_map(fn ($cid) => $cats[$cid]['name'] ?? '', $catIds));
        $catSlugs = array_filter(array_map(fn ($cid) => $cats[$cid]['slug'] ?? '', $catIds));

        // Suggested NetWix genres: the umbrella (if any) first, then any mapped source categories.
        $genreNames = $this->config->umbrellaGenre ? [$this->config->umbrellaGenre] : [];
        foreach ($catSlugs as $s) {
            if (isset($this->config->genreMap[$s])) {
                $genreNames[] = $this->config->genreMap[$s];
            }
        }
        $genreNames = array_values(array_unique($genreNames));

        $extra = [
            'slug' => $slug,
            'media_id' => (int) ($el['featured_media'] ?? 0),
            'is_movie' => $this->isMovie($catSlugs),
            'genre_names' => $genreNames,
        ];
        // Adult category (e.g. 24-hdx "18") → flag it so ImportService imports the title as 18+/VIP.
        if ($this->config->adultCatSlug !== null && in_array($this->config->adultCatSlug, $catSlugs, true)) {
            $extra['adult'] = true;
        }

        return new RemoteSeries(
            source: $this->config->id,
            sourceKey: (string) $id,   // WP post id — resolves the stream via {apiUrl}
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: null,
            year: $this->parseYear($rawTitle, (string) ($el['date'] ?? '')),
            dubType: $this->detectDub($rawTitle.' '.implode(' ', $catNames)),
            extra: $extra,
        );
    }

    /** @param string[] $catSlugs */
    private function isMovie(array $catSlugs): bool
    {
        if ($this->config->seriesCatSlug !== null) {
            return ! in_array($this->config->seriesCatSlug, $catSlugs, true); // default movie
        }
        if ($this->config->movieCatSlug !== null) {
            return in_array($this->config->movieCatSlug, $catSlugs, true);    // default series
        }

        return $this->config->defaultContentType === 'movie';
    }

    /** Film year: from the title's "(YYYY)" (when the site puts it there), else the post date. */
    private function parseYear(string $rawTitle, string $date): ?int
    {
        if ($this->config->yearFromTitleParen && preg_match('~\((19|20)\d{2}\)~', $rawTitle, $ym)) {
            return (int) trim($ym[0], '()');
        }
        if (preg_match('~(20\d{2})~', $date, $ym)) {
            return (int) $ym[1];
        }

        return null;
    }

    /** @return array<int,array{name:string,slug:string}> */
    private function fetchCategoryMap(): array
    {
        $map = [];
        try {
            $json = $this->http()->get($this->config->base.'/wp-json/wp/v2/categories', [
                'per_page' => 100,
                '_fields' => 'id,name,slug',
            ])->json();
            if (is_array($json)) {
                foreach ($json as $c) {
                    if (isset($c['id'])) {
                        $map[(int) $c['id']] = [
                            'name' => (string) ($c['name'] ?? ''),
                            'slug' => (string) ($c['slug'] ?? ''),
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // classification is best-effort; genres/type just stay unset
        }

        return $map;
    }

    /** @param int[] $mediaIds  @return array<int,string> */
    private function fetchPosters(array $mediaIds): array
    {
        $map = [];
        try {
            $json = $this->http()->get($this->config->base.'/wp-json/wp/v2/media', [
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
            // posters are best-effort
        }

        return $map;
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

    /** Strip the trailing "(YYYY)" / dub tags / "| sitename" so the display title is just the name. */
    private function cleanTitle(string $raw): string
    {
        $tokens = array_values(array_filter([
            $this->config->stripYearParen ? '\((19|20)\d{2}\)' : null,
            'พากย์ไทย', 'ซับไทย', 'ซับ', 'พากย์', 'HD',
            $this->config->siteTagRegex,
        ]));
        $pattern = '~\s*('.implode('|', $tokens).')\s*$~ui';

        $t = trim($raw);
        for ($i = 0; $i < 3; $i++) {
            $next = trim(preg_replace($pattern, '', $t) ?? $t);
            if ($next === $t) {
                break;
            }
            $t = $next;
        }

        return trim($t) === '' ? $raw : $t;
    }

    public function fetchEpisodes(RemoteSeries $series): array
    {
        // Movies / single-video titles → one playable episode.
        if ($series->extra['is_movie'] ?? ($this->config->defaultContentType === 'movie')) {
            return [['number' => 1, 'ref' => '1']];
        }

        $slug = trim((string) ($series->extra['slug'] ?? $series->sourceKey), '/');
        if ($slug === '') {
            return [['number' => 1, 'ref' => '1']];
        }
        try {
            $html = $this->http()->withHeaders(['Referer' => $this->config->base.'/'])
                ->get($this->config->base.'/'.$slug.'/')->body();
        } catch (\Throwable) {
            return [['number' => 1, 'ref' => '1']];
        }

        $nums = [];
        $re = $this->config->episodeMode === HalimSiteConfig::EP_SLUG
            // <a href="/{slug}-ep-N/"> — scope to THIS slug so related-title links can't leak in.
            ? '~'.preg_quote($slug, '~').'-ep-(\d+)/~i'
            // <option value="N"> ตอนที่ N  (the lang <select> is value="Thai", so "ตอนที่" keeps them apart)
            : '~<option[^>]*value="(\d+)"[^>]*>\s*ตอนที่~u';
        if (preg_match_all($re, $html, $m)) {
            foreach ($m[1] as $n) {
                $nums[(int) $n] = true;
            }
        }
        $nums = array_keys($nums);
        sort($nums);
        if ($nums === []) {
            $nums = [1]; // episode list not found → expose one playable episode
        }

        return array_map(fn ($n) => ['number' => $n, 'ref' => (string) $n], $nums);
    }

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        $slug = trim((string) ($series->extra['slug'] ?? $series->sourceKey), '/');
        if ($slug === '') {
            return null;
        }
        try {
            $html = $this->http()->withHeaders(['Referer' => $this->config->base.'/'])
                ->get($this->config->base.'/'.$slug.'/')->body();
        } catch (\Throwable) {
            return null;
        }

        return SynopsisScraper::fromHtml($html);
    }

    /**
     * Re-fetch a fresh poster: first from the WP media endpoint by the stored featured_media id (the
     * same source the catalogue used), else the title page's og:image. Used to heal a dead hotlink.
     */
    public function fetchPoster(RemoteSeries $series): ?string
    {
        $mediaId = (int) ($series->extra['media_id'] ?? 0);
        if ($mediaId > 0) {
            $posters = $this->fetchPosters([$mediaId]);
            if (! empty($posters[$mediaId])) {
                return $posters[$mediaId];
            }
        }

        $slug = trim((string) ($series->extra['slug'] ?? $series->sourceKey), '/');
        if ($slug === '') {
            return null;
        }
        try {
            $html = $this->http()->withHeaders(['Referer' => $this->config->base.'/'])
                ->get($this->config->base.'/'.$slug.'/')->body();
        } catch (\Throwable) {
            return null;
        }

        return PosterScraper::fromHtml($html);
    }

    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        $episode = $sourceRef !== '' ? $sourceRef : '1';

        // A title only has some of the langs (and maybe only server 2), so try each combo until the
        // player returns a real iframe hash. First hit wins (usually 1 request for a Thai-dub movie).
        foreach ($this->config->servers as $server) {
            foreach ($this->config->langs as $lang) {
                $playerId = $this->playerHash($sourceKey, $episode, $server, $lang);
                if ($playerId === null) {
                    continue;
                }
                $referer = $this->config->playerHost."/index_th.php?id={$playerId}";
                $masterUrl = $this->config->playerHost."/newplaylist/{$playerId}/{$playerId}.m3u8";

                // The master lists bitrate variants. NetWix's HLS proxy re-points nested playlists
                // back to the manifest route (which would loop on a master), so resolve down to a
                // single MEDIA playlist here — the proxy then only rewrites segment URIs.
                $media = $this->resolveMediaPlaylist($masterUrl, $referer);
                if ($media !== null) {
                    return new RemoteStream(RemoteStream::KIND_HLS, $media, $referer);
                }
            }
        }

        return null;   // no language/server produced a playable stream (caller shows "preparing")
    }

    /**
     * POST the Halim player ajax for one (episode, server, lang) and return the player hash id, or
     * null if the title has no stream for that language/server ("ไม่พบรายการ" / non-iframe response).
     */
    private function playerHash(string $postid, string $episode, string $server, string $lang): ?string
    {
        try {
            $resp = $this->http()->asForm()->withHeaders([
                'Referer' => $this->config->base.'/',
                'Origin' => $this->config->base,
                'X-Requested-With' => 'XMLHttpRequest',
            ])->post($this->config->apiUrl, [
                'action' => 'halim_ajax_player',
                'nonce' => '',
                'episode' => $episode,
                'server' => $server,
                'postid' => $postid,
                'lang' => $lang,   // REQUIRED — must match a language <select> value on the title
                'title' => '',
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->ok()) {
            return null;
        }

        // Response is an iframe → {playerHost}/index_th.php?id={hash} (server 2 = new_player.php).
        return preg_match('~(?:index_th|new_player)\.php\?id=([a-f0-9]+)~i', $resp->body(), $m) ? $m[1] : null;
    }

    /**
     * Fetch the newplaylist master and return a single playable media-playlist URL:
     *  - a master with #EXT-X-STREAM-INF variants → absolute URL of the highest-bandwidth variant
     *  - an already-flat media playlist (#EXTINF, no variants) → the master URL itself
     *  - anything else / fetch failure → null
     */
    private function resolveMediaPlaylist(string $masterUrl, string $referer): ?string
    {
        try {
            $body = $this->http()->withHeaders(['Referer' => $referer])->get($masterUrl)->body();
        } catch (\Throwable) {
            return null;
        }
        if (! str_contains($body, '#EXTM3U')) {
            return null;
        }

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $best = null;
        $bestBw = -1;
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            if (! str_starts_with(trim($lines[$i]), '#EXT-X-STREAM-INF')) {
                continue;
            }
            $bw = preg_match('~BANDWIDTH=(\d+)~', $lines[$i], $bm) ? (int) $bm[1] : 0;
            $uri = trim($lines[$i + 1] ?? '');
            if ($uri === '' || str_starts_with($uri, '#')) {
                continue;
            }
            if ($bw > $bestBw) {
                $bestBw = $bw;
                $best = $uri;
            }
        }

        if ($best !== null) {
            return $this->absolute($best, $masterUrl);   // master → chosen variant
        }

        // Already a flat media playlist (segments, no nested #EXT-X-STREAM-INF) → play as-is.
        return str_contains($body, '#EXTINF') ? $masterUrl : null;
    }

    private function absolute(string $uri, string $base): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
        if (str_starts_with($uri, '/')) {
            return $origin.$uri;
        }

        return $origin.rtrim(dirname($p['path'] ?? '/'), '/').'/'.$uri;
    }
}
