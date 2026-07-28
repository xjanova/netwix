<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * anifume.com (การ์ตูน/อนิเมะ) — bespoke PHP site, Thai-sub/dub anime. Reverse-engineered 2026-07-10
 * (see [[anifume.com recon …]]). The catalogue + episode list are plain GETs; the stream is a direct
 * progressive MP4 on the rukoluo CDN (signed + short-expiry), so it resolves on demand and plays like
 * rongyok / stored mp4 — no new player infra.
 *
 * Chain (all confirmed server-side, no browser / auth / ad-gate):
 *   1. catalogue → GET /page/{N}      → cards: <a href="/{id}"> (a SERIES) + poster + title
 *   2. episodes  → GET /{id}          → lists slugged watch links /{id}/{slug}-{NN} (one per episode)
 *   3. resolve   → GET /{id}/{slug}-{NN}  → <iframe src="/player/…{signed}"> (also /player2 backup)
 *                  → GET /player/…      → jwplayer setup "file":"https://…rukoluo…/…-NN.mp4?m=&e="
 *
 * IMPORTANT: the BARE /{id} page also hosts an AJAX-gated decoy player (#… div, empty to scrapers);
 * IGNORE it — the real stream is only on the SLUGGED /{id}/{slug}-{NN} watch page.
 */
class AnifumeSource implements MediaSource, ProvidesSynopsis
{
    public const BASE = 'https://anifume.com';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function id(): string
    {
        return 'anifume';
    }

    public function displayName(): string
    {
        return 'Anifume (การ์ตูน/อนิเมะ)';
    }

    public function defaultContentType(): string
    {
        return 'series';
    }

    public function isProgressive(): bool
    {
        return true;   // direct rukoluo-CDN MP4
    }

    public function umbrellaGenre(): ?string
    {
        return 'อนิเมะ';   // every title files under อนิเมะ so it shows on /anime (same as anime108)
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(45)->retry(2, 400);
    }

    /**
     * Scrape the paginated latest-updates listing (/, /page/2…N), emitting per page so a timeout keeps
     * earlier pages. Each series (numeric id) is emitted once even though it reappears across pages as
     * it updates. Stops at the first page with no NEW series (end of catalogue) or a failed fetch.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $seen = [];
        $total = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $url = self::BASE.($page > 1 ? "/page/{$page}" : '/');
            try {
                $html = $this->http()->get($url)->body();
            } catch (\Throwable) {
                break;
            }

            $batch = [];
            // card = <div class="col-img"><a href="/{id}"><img src="{poster}" ... alt="{title}">
            if (preg_match_all('~col-img">\s*<a href="'.preg_quote(self::BASE, '~').'/(\d+)">\s*<img src="([^"]+)"[^>]*alt="([^"]*)"~', $html, $ms, PREG_SET_ORDER)) {
                foreach ($ms as $m) {
                    $id = $m[1];
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    if ($s = $this->makeSeries($id, html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[2])) {
                        $batch[] = $s;
                    }
                }
            }

            if ($batch) {
                $onBatch($batch);
                $total += count($batch);
            } else {
                break;   // a page with no new series = past the end
            }
        }

        return $total;
    }

    private function makeSeries(string $id, string $rawTitle, string $posterUrl): RemoteSeries
    {
        return new RemoteSeries(
            source: 'anifume',
            sourceKey: $id,
            title: $rawTitle,
            cleanTitle: $this->cleanTitle($rawTitle),
            posterUrl: str_starts_with($posterUrl, 'http') ? $posterUrl : self::BASE.'/'.ltrim($posterUrl, '/'),
            dubType: $this->detectDub($rawTitle),
        );
    }

    /** Strip the trailing "ตอนที่ … ซับไทย/พากย์ไทย" so the stored title is the clean series name. */
    private function cleanTitle(string $raw): string
    {
        $t = preg_replace('~\s*(ตอนที่|ตอนล่าสุด|EP\.?)\s.*$~u', '', $raw) ?? $raw;
        $t = preg_replace('~\s*(ซับไทย|พากย์ไทย|\[จบ\]|\(จบ\))\s*$~u', '', trim($t)) ?? $t;

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

    /**
     * Episodes = the watch links the series page (/{id}) lists, as `.eplink` anchors.
     *
     * 2026-07-28: anifume replaced its readable per-episode slugs (`/{id}/{Slug}-07`) with opaque
     * tokens (`/{id}/6k-OBbV2…QHS`), so EVERY stored ref started 404ing and the whole source went
     * unresolvable overnight — the site itself was fine throughout. The episode NUMBER is no longer
     * in the URL either; it now only exists in the link text ("… ตอนที่ 7 ซับไทย"), which is what this
     * reads. Refs are the full relative path so resolveByRef can hit them directly.
     *
     * @return array<int,array{number:int,ref:string}>
     */
    public function fetchEpisodes(RemoteSeries $series): array
    {
        try {
            $html = $this->http()->get(self::BASE.'/'.$series->sourceKey)->body();
        } catch (\Throwable) {
            return [];
        }

        $byNum = [];
        $re = '~href="'.preg_quote(self::BASE, '~').'/'.preg_quote($series->sourceKey, '~')
            .'/([A-Za-z0-9\-_.]+)"[^>]*>([^<]*)</a>~u';

        if (preg_match_all($re, $html, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $i => $m) {
                // "ตอนที่ 7 ซับไทย" → 7. A title with no per-episode numbering (a movie/OVA) still
                // yields one playable entry, numbered by document order.
                $n = preg_match('~ตอนที่\s*(\d+)~u', $m[2], $nm) ? (int) $nm[1] : $i + 1;
                $byNum[$n] = ['number' => $n, 'ref' => $series->sourceKey.'/'.$m[1]];
            }
        }

        ksort($byNum);

        return array_values($byNum);
    }

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        try {
            $html = $this->http()->get(self::BASE.'/'.$series->sourceKey)->body();
        } catch (\Throwable) {
            return null;
        }
        if (preg_match('~<meta name="description" content="([^"]+)"~', $html, $m)) {
            $d = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // strip the site's boilerplate "ดูการ์ตูน … ตอนล่าสุด" wrapper if that's all it is
            return $d !== '' ? $d : null;
        }

        return null;
    }

    /**
     * Resolve the direct MP4 for one episode. $sourceRef is the relative watch path "{id}/{token}".
     *
     * The watch page no longer embeds the player. Since 2026-07-28 it ships inline jQuery that fetches
     * the iframe from an obfuscated endpoint, and offers two of them — `mainv()` for the default
     * server and `mirp2()` behind the site's own "ตัวเล่นสำรอง" button:
     *
     *   1. watch page          → url: "/{key}?u={blob}"      (one per server, in that order)
     *   2. that endpoint       → <iframe src="{BASE}/player/s=…&f=…&m=…&e=…">
     *   3. the player page     → jwplayer sources → https://aNN.rukoluo.com/…mp4?m=…&e=…
     *
     * Both the iframe URL and the MP4 carry an `e=<unix>` expiry, which is why the resolver runs at
     * watch time and its result is only cached until shortly before that stamp.
     */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        $watch = self::BASE.'/'.ltrim($sourceRef, '/');
        try {
            $html = $this->http()->withHeaders(['Referer' => self::BASE.'/'])->get($watch)->body();
        } catch (\Throwable) {
            return null;
        }

        // Every player the page offers, primary first — the backup exists precisely for the episodes
        // whose main server is down, so trying it costs one request and saves the title.
        if (! preg_match_all('~url:\s*"(/[A-Za-z0-9]+\?u=[^"]+)"~', $html, $ms)) {
            return null;
        }

        foreach (array_unique($ms[1]) as $endpoint) {
            $playerUrl = $this->playerIframe(self::BASE.html_entity_decode($endpoint, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $watch);
            if ($playerUrl !== null && ($mp4 = $this->extractMp4($playerUrl, $watch)) !== null) {
                return new RemoteStream(RemoteStream::KIND_MP4, $mp4);
            }
        }

        return null;
    }

    /** GET the obfuscated player endpoint → the on-site player iframe URL it returns, or null. */
    private function playerIframe(string $endpoint, string $watchRef): ?string
    {
        try {
            $body = $this->http()->withHeaders([
                'Referer' => $watchRef,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => '*/*',
            ])->get($endpoint)->body();
        } catch (\Throwable) {
            return null;
        }

        return preg_match('~<iframe[^>]+src="([^"]+)"~i', $body, $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;
    }

    /** GET the player iframe and pull the highest-quality jwplayer source ("file": "…mp4"). */
    private function extractMp4(string $playerUrl, string $watchRef): ?string
    {
        try {
            $body = $this->http()->withHeaders(['Referer' => $watchRef])->get($playerUrl)->body();
        } catch (\Throwable) {
            return null;
        }
        // jwplayer setup lists sources full-quality-first, then -q360; take the first real .mp4.
        if (preg_match('~"file"\s*:\s*"(https?://[^"]+?\.mp4[^"]*)"~', $body, $m)) {
            return str_replace('\\/', '/', $m[1]);
        }
        // Fall back to any absolute .mp4 on the page, in case the player markup changes shape again.
        if (preg_match('~https?://[^"\'\s<>]+?\.mp4[^"\'\s<>]*~', $body, $m)) {
            return str_replace('\\/', '/', $m[0]);
        }

        return null;
    }
}
