<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * 24-hdx.com — WordPress + Halim theme hosting Thai-dubbed/subbed MOVIES (~6,500 titles).
 * Same stack as [Anime108Source]; the differences are all constants (see the site API map note
 * "24-hdx.com — site API map"). Verified flow (2026-07):
 *   1. catalog  → WP REST /wp-json/wp/v2/posts (100/page) + /media posters + /categories genres
 *   2. episodes → single video → one playable episode (movies)
 *   3. resolve  → POST https://api.24-hdx.com/get.php (action=halim_ajax_player, postid, episode,
 *                 server=1, lang=Thai — lang is REQUIRED) → iframe main.24playerhd.com/index_th.php?id={hash}
 *                 → HLS master newplaylist/{hash}/{hash}.m3u8 → pick best-bitrate variant
 *
 * Key differences from anime108:
 *  - the player ajax lives on a SEPARATE subdomain https://api.24-hdx.com/get.php (absolute), not /api/get.php
 *  - `lang=Thai` MUST be sent (empty/other → "ไม่พบรายการ ... ภาษาที่คุณเลือกไม่มีอยู่")
 *  - these are real movies → defaultContentType=movie and NO anime umbrella (so they land on /movies).
 */
class Movie24hdxSource implements MediaSource
{
    public const BASE = 'https://www.24-hdx.com';
    public const API = 'https://api.24-hdx.com/get.php';   // Halim player ajax — separate subdomain
    public const PLAYER = 'https://main.24playerhd.com';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** Category slug that marks a title as a multi-episode series (else it's a movie). */
    private const CAT_SERIES_SLUG = 'series';

    /**
     * 24-hdx category slug → NetWix genre name (Thai). Only real NetWix genres are targeted, and
     * NEVER the anime umbrellas (อนิเมะ/การ์ตูน) — those get filtered off /movies. The main story
     * genres below cover virtually every movie, so each import lands in at least one /movies row.
     */
    private const GENRE_MAP = [
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
    ];

    public function id(): string
    {
        return '24hdx';
    }

    public function displayName(): string
    {
        return '24-HDX (ภาพยนตร์)';
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
        return null;    // real movies — no umbrella, so they show on /movies (not /anime)
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

        $catSlugs = array_filter(array_map(
            fn ($cid) => $cats[(int) $cid]['slug'] ?? '',
            (array) ($el['categories'] ?? [])
        ));
        $isMovie = ! in_array(self::CAT_SERIES_SLUG, $catSlugs, true);

        // Suggested NetWix genres, mapped from the source's own categories (no anime umbrella).
        $genreNames = [];
        foreach ($catSlugs as $s) {
            if (isset(self::GENRE_MAP[$s])) {
                $genreNames[] = self::GENRE_MAP[$s];
            }
        }
        $genreNames = array_values(array_unique($genreNames));

        // Film year: prefer the "(YYYY)" in the title, else the post date.
        $year = null;
        if (preg_match('~\((19|20)\d{2}\)~', $rawTitle, $ym)) {
            $year = (int) trim($ym[0], '()');
        } elseif (preg_match('~(20\d{2})~', (string) ($el['date'] ?? ''), $ym)) {
            $year = (int) $ym[1];
        }

        return new RemoteSeries(
            source: '24hdx',
            sourceKey: (string) $id,   // WP post id — resolves the stream via api.24-hdx.com/get.php
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: null,
            year: $year,
            dubType: $this->detectDub($rawTitle),
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
            // classification is best-effort; genres just stay unset
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

    /** Strip the trailing "(YYYY)" and dub tags so the display title is just the film name. */
    private function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        for ($i = 0; $i < 3; $i++) {
            $next = trim(preg_replace('~\s*(\((19|20)\d{2}\)|พากย์ไทย|ซับไทย|ซับ|พากย์|HD|\|\s*24-?hdx)\s*$~ui', '', $t) ?? $t);
            if ($next === $t) {
                break;
            }
            $t = $next;
        }

        return trim($t) === '' ? $raw : $t;
    }

    public function fetchEpisodes(RemoteSeries $series): array
    {
        // Movies are a single video → one playable episode.
        if ($series->extra['is_movie'] ?? true) {
            return [['number' => 1, 'ref' => '1']];
        }

        // Series: the detail page carries an episode <select> of
        // <option value="N"> ตอนที่ N</option> (the language <select> is value="Thai", so the
        // "ตอนที่" anchor keeps them apart). Each option value is a playable `episode` param.
        $slug = trim((string) ($series->extra['slug'] ?? ''), '/');
        if ($slug === '') {
            return [['number' => 1, 'ref' => '1']];
        }
        try {
            $html = $this->http()->withHeaders(['Referer' => self::BASE.'/'])
                ->get(self::BASE.'/'.$slug.'/')->body();
        } catch (\Throwable) {
            return [['number' => 1, 'ref' => '1']];
        }

        $nums = [];
        if (preg_match_all('~<option[^>]*value="(\d+)"[^>]*>\s*ตอนที่~u', $html, $m)) {
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

    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        try {
            $resp = $this->http()->asForm()->withHeaders([
                'Referer' => self::BASE.'/',
                'Origin' => self::BASE,
                'X-Requested-With' => 'XMLHttpRequest',
            ])->post(self::API, [
                'action' => 'halim_ajax_player',
                'nonce' => '',
                'episode' => $sourceRef !== '' ? $sourceRef : '1',
                'server' => '1',
                'postid' => $sourceKey,
                'lang' => 'Thai',   // REQUIRED — the language <select> value
                'title' => '',
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $resp->ok()) {
            return null;
        }
        // Response is an iframe → main.24playerhd.com/index_th.php?id={hash}
        if (! preg_match('~index_th\.php\?id=([a-f0-9]+)~i', $resp->body(), $m)) {
            return null;
        }
        $playerId = $m[1];
        $referer = self::PLAYER."/index_th.php?id={$playerId}";
        $masterUrl = self::PLAYER."/newplaylist/{$playerId}/{$playerId}.m3u8";

        // The master lists bitrate variants. NetWix's HLS proxy re-points nested playlists back to the
        // manifest route (which would loop on a master), so resolve down to a single MEDIA playlist
        // here — the proxy then only ever rewrites segment URIs.
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
