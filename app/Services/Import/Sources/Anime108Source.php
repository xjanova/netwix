<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * anime108.com — WordPress 6.5.8 + HalimMovies theme hosting CN/JP anime (การ์ตูน/อนิเมะ),
 * ~1,616 titles. Verified flow (2026-07):
 *   1. catalog  → WP REST /wp-json/wp/v2/posts (100/page) + /media for posters + /categories for genres
 *   2. episodes → GET /{slug}/  (parse the <option value="/{slug}-ep-N/"> episode <select>)
 *   3. resolve  → POST /api/get.php (action=halim_ajax_player, postid, episode, server=1, lang=Thai)
 *                 → iframe main.108player.com/index_th.php?id={hash}
 *                 → HLS master newplaylist/{hash}/{hash}.m3u8 → pick best bitrate variant
 *
 * Notes:
 *  - The site's admin-ajax.php is Cloudflare-blocked; the theme resolves through the custom
 *    /api/get.php instead (no nonce required), which IS reachable server-side.
 *  - The single title post_id resolves every episode via the `episode` param.
 *  - Streams are HLS, played through NetWix's server-side proxy (StreamController) like wow-drama.
 */
class Anime108Source implements MediaSource
{
    public const BASE = 'https://www.anime108.com';
    public const PLAYER = 'https://main.108player.com';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** WP category slug that marks a title as a movie (→ type=movie when auto-typing). */
    private const CAT_MOVIE_SLUG = 'the-movie';

    /**
     * anime108 genre category slug → NetWix genre name (Thai). Only well-known genres are mapped;
     * every imported title also gets the "อนิเมะ" umbrella. Used by ImportService when auto_genres
     * is on. All targets already exist in the seeded genre set, so no odd auto-slugs are created.
     */
    private const GENRE_MAP = [
        'action' => 'แอ็กชัน', 'martial-arts' => 'แอ็กชัน', 'super-power' => 'แอ็กชัน', 'samurai' => 'แอ็กชัน',
        'adventure' => 'ผจญภัย', 'isekai' => 'ผจญภัย',
        'comedy' => 'ตลก', 'parody' => 'ตลก',
        'drama' => 'ดราม่า', 'slice-of-life' => 'ดราม่า', 'josei' => 'ดราม่า', 'seinen' => 'ดราม่า',
        'fantasy' => 'แฟนตาซี & ไซไฟ', 'sci-fi' => 'แฟนตาซี & ไซไฟ', 'magic' => 'แฟนตาซี & ไซไฟ',
        'supernatural' => 'แฟนตาซี & ไซไฟ', 'mecha' => 'แฟนตาซี & ไซไฟ', 'space' => 'แฟนตาซี & ไซไฟ',
        'romance' => 'โรแมนติก', 'harem' => 'โรแมนติก', 'shoujo' => 'โรแมนติก',
        'horror' => 'สยองขวัญ', 'demons' => 'สยองขวัญ', 'vampire' => 'สยองขวัญ',
        'mystery' => 'อาชญากรรม', 'detective' => 'อาชญากรรม', 'suspense' => 'อาชญากรรม', 'psychological' => 'อาชญากรรม',
    ];

    public function id(): string
    {
        return 'anime108';
    }

    public function displayName(): string
    {
        return 'Anime108 (การ์ตูน/อนิเมะ)';
    }

    public function defaultContentType(): string
    {
        return 'series';
    }

    public function isProgressive(): bool
    {
        return false;   // HLS — streams through the server proxy, no stored preview needed
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
            $resp = $this->http()->get(self::BASE.'/wp-json/wp/v2/posts', [
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

            /** @var RemoteSeries[] $items */
            $items = [];
            $mediaIds = [];
            foreach ($posts as $el) {
                if (($s = $this->parsePost($el, $cats)) !== null) {
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

            $onBatch($items);
            $total += count($items);

            if (count($posts) < 100) {
                break; // last page
            }
        }

        return $total;
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
        $isMovie = in_array(self::CAT_MOVIE_SLUG, $catSlugs, true);

        // Suggested NetWix genres (umbrella "อนิเมะ" first, then any mapped source genres).
        $genreNames = ['อนิเมะ'];
        foreach ($catSlugs as $s) {
            if (isset(self::GENRE_MAP[$s])) {
                $genreNames[] = self::GENRE_MAP[$s];
            }
        }
        $genreNames = array_values(array_unique($genreNames));

        $year = null;
        if (preg_match('~(20\d{2})~', (string) ($el['date'] ?? ''), $ym)) {
            $year = (int) $ym[1];
        }

        return new RemoteSeries(
            source: 'anime108',
            sourceKey: (string) $id,   // WP post id — resolves every episode via /api/get.php
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: null,
            year: $year,
            dubType: $this->detectDub($rawTitle.' '.implode(' ', $catNames)),
            extra: [
                'slug' => $slug,
                'media_id' => (int) ($el['featured_media'] ?? 0),
                'is_movie' => $isMovie,
                'genre_names' => $genreNames,
            ],
        );
    }

    /** @return array<int,array{name:string,slug:string}> */
    private function fetchCategoryMap(): array
    {
        $map = [];
        try {
            $json = $this->http()->get(self::BASE.'/wp-json/wp/v2/categories', [
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
            // classification is best-effort; dub/movie just stay unset
        }

        return $map;
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

    private function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        for ($i = 0; $i < 3; $i++) {
            $next = trim(preg_replace('~\s*(พากย์ไทย|ซับไทย|ซับ|พากย์|\|\s*anime108|HD)\s*$~ui', '', $t) ?? $t);
            if ($next === $t) {
                break;
            }
            $t = $next;
        }

        return trim($t) === '' ? $raw : $t;
    }

    public function fetchEpisodes(RemoteSeries $series): array
    {
        $slug = (string) ($series->extra['slug'] ?? $series->sourceKey);
        $html = $this->http()->get(self::BASE.'/'.trim($slug, '/').'/')->body();

        // Episode picker is a <select> of <option value="/{slug}-ep-N/">ตอนที่ N</option>.
        // Scope the match to THIS slug so "related title" links elsewhere on the page can't leak in.
        $nums = [];
        if (preg_match_all('~'.preg_quote($slug, '~').'-ep-(\d+)/~i', $html, $m)) {
            foreach ($m[1] as $n) {
                $nums[(int) $n] = true;
            }
        }
        $nums = array_keys($nums);
        sort($nums);

        // Movies / single-video titles have no episode <select> — expose one playable episode.
        if ($nums === []) {
            $nums = [1];
        }

        return array_map(fn ($n) => ['number' => $n, 'ref' => (string) $n], $nums);
    }

    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        try {
            $resp = $this->http()->asForm()->withHeaders([
                'Referer' => self::BASE.'/',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->post(self::BASE.'/api/get.php', [
                'action' => 'halim_ajax_player',
                'nonce' => '',
                'episode' => $sourceRef,
                'server' => '1',
                'postid' => $sourceKey,
                'lang' => 'Thai',
                'title' => '',
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $resp->ok()) {
            return null;
        }
        // The response is an iframe → main.108player.com/index_th.php?id={hash}
        if (! preg_match('~index_th\.php\?id=([a-f0-9]+)~i', $resp->body(), $m)) {
            return null;
        }
        $playerId = $m[1];
        $referer = self::PLAYER."/index_th.php?id={$playerId}";
        $masterUrl = self::PLAYER."/newplaylist/{$playerId}/{$playerId}.m3u8";

        // The master lists 2 bitrate variants. NetWix's HLS proxy re-points nested playlists back to
        // the manifest route (which would loop on a master), so resolve down to a single MEDIA
        // playlist here — the proxy then only ever rewrites segment URIs.
        $media = $this->resolveMediaPlaylist($masterUrl, $referer);
        if ($media === null) {
            return null;   // transient (player/CDN down) — caller shows "preparing" and retries
        }

        return new RemoteStream(RemoteStream::KIND_HLS, $media, $referer);
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
