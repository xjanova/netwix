<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Support\SynopsisScraper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * wow-drama.com — WordPress site (theme "wowdrama" + "miru-player" plugin) hosting full-length
 * CN/KR/JP series. Verified flow (2026-07):
 *   1. catalog  → WP REST /wp-json/wp/v2/posts?categories=1 (100/page) + /media for posters
 *   2. episodes → GET /{slug}/  (parse the .mp-ep-btn buttons → wp post ids, in order)
 *   3. resolve  → POST /wp-admin/admin-ajax.php action=miru_custom_player&post_id={id}
 *                 → getplay-cdn embed hash → HLS at getplay-cdn.com/api/stream/{hash}/index.m3u8
 * PHP port of the Hive Download WowDramaClient.
 */
class WowDramaSource implements MediaSource, ProvidesSynopsis
{
    public const BASE = 'https://wow-drama.com';
    public const GETPLAY = 'https://getplay-cdn.com';
    private const CAT_ID = 1;
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

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

    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $total = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $resp = $this->http()->get(self::BASE.'/wp-json/wp/v2/posts', [
                'categories' => self::CAT_ID,
                'per_page' => 100,
                'page' => $page,
                '_fields' => 'id,slug,title,featured_media',
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
                if (($s = $this->parsePost($el)) !== null) {
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

    private function parsePost(array $el): ?RemoteSeries
    {
        $slug = (string) ($el['slug'] ?? '');
        if ($slug === '') {
            return null;
        }
        $rawTitle = isset($el['title']['rendered'])
            ? trim(html_entity_decode((string) $el['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : $slug;
        $mediaId = (int) ($el['featured_media'] ?? 0);

        $year = null;
        if (preg_match('~(?:\((\d{4})\)|-(\d{4})(?:$|[/\-]))~', $rawTitle.' '.$slug, $ym)) {
            $year = (int) ($ym[1] ?: $ym[2]);
        }

        return new RemoteSeries(
            source: 'wowdrama',
            sourceKey: $slug,
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: null,
            year: $year,
            dubType: $this->detectDub($rawTitle),
            extra: ['media_id' => $mediaId, 'slug' => $slug],
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
        $t = preg_replace('~^\s*ดู(ซีรี่ส์|ซีรี่ย์|ซีรีส์|หนัง)(จีน|เกาหลี|ญี่ปุ่น|ไทย|ฝรั่ง)?\s*~u', '', $raw) ?? $raw;
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
        if (preg_match_all('~<button class="mp-ep-btn[^"]*"\s+data-id="(\d+)"~', $html, $m)) {
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
        $resp = $this->http()->asForm()->withHeaders([
            'Referer' => self::BASE.'/'.$sourceKey.'/',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->post(self::BASE.'/wp-admin/admin-ajax.php', [
            'action' => 'miru_custom_player',
            'post_id' => $sourceRef,
        ]);

        if (! $resp->ok()) {
            return null;
        }
        if (! preg_match('~getplay-cdn\.com/embed/([a-f0-9]{16,})~', $resp->body(), $m)) {
            return null;
        }
        $hash = $m[1];

        return new RemoteStream(
            RemoteStream::KIND_HLS,
            self::GETPLAY."/api/stream/{$hash}/index.m3u8",
            self::GETPLAY."/embed/{$hash}",
        );
    }
}
