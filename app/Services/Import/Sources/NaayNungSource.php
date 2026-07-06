<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\BackupPoolSource;
use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Support\SynopsisScraper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * นายหนัง (9.9nung.com) — WordPress + **Dooplay** theme (`domovie2569`), Thai movie/series catalogue.
 * A DIFFERENT engine from the Halim sites ([HalimSource]), so it's its own class:
 *
 *  - Catalogue: the `movies`/`tvshows` CPTs are NOT in WP REST and the site's own search DB-errors, so
 *    the catalogue is scraped from the genre archives (`/genre/{slug}/page/N/`, Dooplay `.item` cards).
 *  - Resolve (verified 2026-07-06, bypasses the site's on-page casino-ad gate entirely):
 *      1. detail page `/{movies|tvshows}/{slug}/` → iframe `data-src="/fembed.php?v=embed/{FEMBED_ID}…"`
 *      2. real embed `https://fembed.co/embed/{FEMBED_ID}` (JWPlayer) → HLS master
 *         `https://media.vdohls.com/{FEMBED_ID}/playlist.m3u8`
 *      3. master → single 1080p variant `//media.vdohls.com/{token}/video.m3u8`; segments are clean
 *         MPEG-TS disguised as `.jpeg` on `vh006.xyz`, NO `#EXT-X-KEY` (no DRM). Plays through the
 *         NetWix StreamController proxy like the Halim sites.
 *
 * Adult: the site's `erotic` genre ("R18+") is flagged `extra.adult` so [App\Services\Import\
 * ImportService] imports those titles as 18+ AND VIP-premium (is_vip) — owner rule 2026-07-06.
 */
class NaayNungSource implements BackupPoolSource, MediaSource, ProvidesSynopsis
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    private const BASE = 'https://9.9nung.com';

    /** HLS host the fembed player resolves to (the master playlist lives here, keyed by the fembed id). */
    private const MEDIA_HOST = 'https://media.vdohls.com';

    /** The real player host — its Referer is what the vdohls CDN expects. */
    private const EMBED_REFERER = 'https://fembed.co/';

    /** 9.9nung genre slug marking 18+ erotic content → imported as adult + VIP-premium. */
    private const ADULT_GENRE = 'erotic';

    /**
     * Genre archives scraped to build the catalogue. Content genres first (they set the mapped NetWix
     * genre name for a title seen there first), then the country/region archives — many titles are
     * tagged ONLY by origin (Thai + inter are the biggest slices), so content genres alone miss them.
     * The adult genre is LAST so its extra.adult flag wins on a title also listed under a normal genre.
     */
    private const SCRAPE_GENRES = [
        'action', 'adventure', 'comedy', 'crime', 'drama', 'family', 'fantasy', 'history',
        'horror', 'music', 'mystery', 'romance', 'sci-fi', 'thriller', 'war', 'biography',
        'animation', 'documentary', 'short', 'sport',
        'thailand', 'inter', 'china', 'south-korea', 'japan', 'hong-kong', 'india',
        'uk', 'united-kingdom', 'united-states', 'france', 'germany', 'spain', 'canada', 'australia',
        self::ADULT_GENRE,
    ];

    /** 9.9nung genre slug → NetWix genre name (same target genres the Halim sources use). */
    private const GENRE_MAP = [
        'action' => 'แอ็กชัน', 'war' => 'แอ็กชัน',
        'adventure' => 'ผจญภัย',
        'comedy' => 'ตลก',
        'drama' => 'ดราม่า', 'biography' => 'ดราม่า', 'family' => 'ดราม่า',
        'romance' => 'โรแมนติก', 'music' => 'โรแมนติก',
        'horror' => 'สยองขวัญ',
        'thriller' => 'อาชญากรรม', 'crime' => 'อาชญากรรม', 'mystery' => 'อาชญากรรม',
        'fantasy' => 'แฟนตาซี & ไซไฟ', 'sci-fi' => 'แฟนตาซี & ไซไฟ',
        'history' => 'ย้อนยุค',
        'erotic' => 'อีโรติก',
    ];

    public function id(): string
    {
        return '9nung';
    }

    public function displayName(): string
    {
        return 'นายหนัง (9.9nung)';
    }

    public function defaultContentType(): string
    {
        return 'movie';
    }

    public function isProgressive(): bool
    {
        return false;   // HLS — streams through the server proxy
    }

    public function umbrellaGenre(): ?string
    {
        return null;   // real movies — no umbrella, so they show on /movies
    }

    public function isBackupPool(): bool
    {
        return true;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
        ])->timeout(45)->retry(2, 400);
    }

    /**
     * Scrape the genre archives into the catalogue, emitting per genre so a timeout keeps earlier
     * genres (the sync request can be long). A title appears under several genres; it's emitted once —
     * under the FIRST genre that lists it — EXCEPT the adult genre (scraped LAST), which always re-emits
     * so an erotic title's `extra.adult` flag wins even if it was first seen under a normal genre.
     * $maxPages caps the pages scanned PER genre; a genre stops early at its first empty page.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $seen = [];   // sourceKey → true

        foreach (self::SCRAPE_GENRES as $genre) {
            $isAdult = $genre === self::ADULT_GENRE;
            $mapped = self::GENRE_MAP[$genre] ?? null;
            $batch = [];

            for ($page = 1; $page <= $maxPages; $page++) {
                $url = self::BASE.'/genre/'.$genre.'/'.($page > 1 ? "page/{$page}/" : '');
                try {
                    $resp = $this->http()->withHeaders(['Referer' => self::BASE.'/'])->get($url);
                } catch (\Throwable) {
                    break;
                }
                if (! $resp->ok()) {
                    break;
                }
                $items = $this->parseArchive($resp->body());
                if ($items === []) {
                    break;   // past the last page for this genre
                }

                foreach ($items as $it) {
                    // Skip a title already emitted — unless this is the adult genre, which must re-emit
                    // to stamp adult=true over whatever a normal genre wrote first.
                    if (isset($seen[$it['sourceKey']]) && ! $isAdult) {
                        continue;
                    }
                    $seen[$it['sourceKey']] = true;
                    $batch[] = $this->toRemoteSeries($it, $mapped, $isAdult);
                    if (count($batch) >= 100) {
                        $onBatch($batch);
                        $batch = [];
                    }
                }
            }

            if ($batch !== []) {
                $onBatch($batch);
            }
        }

        return count($seen);
    }

    /**
     * Parse one Dooplay archive page's `<article class="item movies|tvshows">` cards.
     *
     * @return array<int,array{sourceKey:string,is_movie:bool,title:string,poster:?string,year:?int}>
     */
    private function parseArchive(string $html): array
    {
        $items = [];
        // split on each card; skip the pre-first-article preamble.
        $parts = preg_split('~<article\b~i', $html) ?: [];
        foreach ($parts as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            if (! preg_match('~href="'.preg_quote(self::BASE, '~').'/(movies|tvshows)/([^"?#]+?)/?"~i', $chunk, $hm)) {
                continue;
            }
            $sourceKey = $hm[1].'/'.trim($hm[2], '/');   // e.g. "movies/thunder-rescue-2026-..."
            $isMovie = strtolower($hm[1]) === 'movies';

            $title = preg_match('~<img[^>]*\balt="([^"]+)"~i', $chunk, $tm)
                ? trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : trim($hm[2], '/');

            // First <img src> — ignore the theme's "no poster" placeholder.
            $poster = null;
            if (preg_match('~<img[^>]*\bsrc="([^"]+)"~i', $chunk, $pm) && ! str_contains($pm[1], '/no/dt_poster')) {
                $poster = $pm[1];
            }

            $year = preg_match('~\((19|20)\d{2}\)~', $title, $ym) ? (int) trim($ym[0], '()') : null;

            $items[$sourceKey] = [
                'sourceKey' => $sourceKey,
                'is_movie' => $isMovie,
                'title' => $title,
                'poster' => $poster,
                'year' => $year,
            ];
        }

        return array_values($items);
    }

    /** @param array{sourceKey:string,is_movie:bool,title:string,poster:?string,year:?int} $it */
    private function toRemoteSeries(array $it, ?string $mappedGenre, bool $isAdult): RemoteSeries
    {
        return new RemoteSeries(
            source: $this->id(),
            sourceKey: $it['sourceKey'],
            title: $it['title'],
            cleanTitle: $this->cleanTitle($it['title']),
            posterUrl: $it['poster'],
            year: $it['year'],
            dubType: $this->detectDub($it['title']),
            extra: [
                'is_movie' => $it['is_movie'],
                'genre_names' => $mappedGenre ? [$mappedGenre] : [],
                'adult' => $isAdult,
            ],
        );
    }

    /** Strip the trailing "(YYYY)" so the display title is just the name. */
    private function cleanTitle(string $raw): string
    {
        $t = trim(preg_replace('~\s*\((19|20)\d{2}\)\s*~u', ' ', $raw) ?? $raw);

        return $t === '' ? $raw : $t;
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

    /** Movies are single-video; series (tvshows) expose one playable source for now. */
    public function fetchEpisodes(RemoteSeries $series): array
    {
        return [['number' => 1, 'ref' => $series->sourceKey]];
    }

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        $html = $this->detailHtml($series->sourceKey);

        return $html !== null ? SynopsisScraper::fromHtml($html) : null;
    }

    /**
     * Resolve the HLS stream. $sourceKey is the detail-page path ("movies/{slug}"); $sourceRef is the
     * same for a movie (a series would carry a per-episode key later). Chain: detail page → fembed id →
     * media.vdohls.com master → single media playlist. No live search / no ad-gate touched.
     */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        // The detail-page path lives in sourceKey ("movies/{slug}"). A force-applied backup passes
        // ref="1" (the Halim movie convention) which we ignore here; only fall back to sourceRef if it
        // is itself a path (a future per-episode key).
        $key = str_contains(trim($sourceKey, '/'), '/') ? $sourceKey : $sourceRef;
        $html = $this->detailHtml($key);
        if ($html === null) {
            return null;
        }

        // The stream is lazy-loaded in the player iframe's data-src (NOT src, which is a YouTube decoy).
        if (! preg_match('~fembed\.php\?v=embed/([A-Za-z0-9_\-]+)~i', $html, $m)) {
            return null;
        }
        $fembedId = $m[1];

        $master = self::MEDIA_HOST.'/'.$fembedId.'/playlist.m3u8';
        $media = $this->resolveMediaPlaylist($master, self::EMBED_REFERER);

        return $media !== null ? new RemoteStream(RemoteStream::KIND_HLS, $media, self::EMBED_REFERER) : null;
    }

    private function detailHtml(string $sourceKey): ?string
    {
        $path = trim($sourceKey, '/');
        if ($path === '' || ! str_contains($path, '/')) {
            return null;
        }
        try {
            return $this->http()->withHeaders(['Referer' => self::BASE.'/'])->get(self::BASE.'/'.$path.'/')->body();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch the master and return a single playable media-playlist URL: the highest-bandwidth variant
     * of a master, or the master itself if it's already a flat media playlist. Mirrors HalimSource so
     * the StreamController proxy only ever rewrites segment URIs, never a nested master (which loops).
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

        return str_contains($body, '#EXTINF') ? $masterUrl : null;
    }

    private function absolute(string $uri, string $base): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        $p = parse_url($base);
        $scheme = $p['scheme'] ?? 'https';
        if (str_starts_with($uri, '//')) {          // protocol-relative (vdohls variants are //host/…)
            return $scheme.':'.$uri;
        }
        $origin = $scheme.'://'.($p['host'] ?? '');
        if (str_starts_with($uri, '/')) {
            return $origin.$uri;
        }

        return $origin.rtrim(dirname($p['path'] ?? '/'), '/').'/'.$uri;
    }
}
