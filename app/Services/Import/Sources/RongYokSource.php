<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\JsonExtract;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * rongyok.com (โรงหยก) — Chinese short-drama. Three GET endpoints, no auth/captcha/ad-gate:
 *   1. /category?category=all           → embedded `seriesData = [...]` (whole catalogue)
 *   2. /watch/?series_id={id}           → embedded `"episodes":[...]` + episodes_count
 *   3. /watch/get_video.php?series_id&ep → {"ok":true,"video_url":"<discord mp4>"}
 * Videos are plain MP4 on Discord's CDN — signed URLs that expire ~24h, so resolve on demand.
 * PHP port of the Hive Download RongYokClient.
 */
class RongYokSource implements MediaSource
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

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(60)->retry(2, 400);
    }

    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $html = $this->http()->get(self::BASE.'/category', ['category' => 'all'])->body();
        $json = JsonExtract::catalogArray($html);
        if (! $json) {
            return 0;
        }
        $arr = json_decode($json, true);
        if (! is_array($arr)) {
            return 0;
        }

        $batch = [];
        foreach ($arr as $el) {
            if (is_array($el) && ($s = $this->parseSeries($el))) {
                $batch[] = $s;
            }
        }
        if ($batch) {
            $onBatch($batch);
        }

        return count($batch);
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

        $clean = null;
        $year = null;
        $dub = null;

        // Poster filename is the most reliable source of clean title / language / year.
        if (preg_match('~poster/(?<title>.+?)-(?<type>พากย์ไทย|ซับไทย)-(?<year>\d{4})-(?<id>\d+)\.~u', $posterRel, $m)) {
            $clean = rawurldecode($m['title']);
            $dub = $m['type'] === 'พากย์ไทย' ? 'thai_dub' : 'thai_sub';
            $year = (int) $m['year'];
        } else {
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
    private const ENDPOINT_FALLBACK = 'get_video.php';

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
}
