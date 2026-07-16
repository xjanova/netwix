<?php

namespace App\Services\Import\Sources;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesGenres;
use App\Services\Import\Contracts\ProvidesPoster;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Support\PosterScraper;
use App\Support\SynopsisScraper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * animeruka.com (การ์ตูน/อนิเมะ) — WordPress + Dooplay theme, Thai-dubbed/subbed anime (~900 series
 * under /anime/ + ~46 films under /movies/). Added 2026-07-16 as a replacement anime source after
 * anime108's segment CDN started TLS-blocking our server (see [[anime108.com — site API map …]]
 * REGRESSION). NOT a Halim site — the Dooplay CPTs aren't exposed over WP REST, so the catalogue is
 * HTML-scraped from the archive pages and it gets its own class (like [Goseries4kSource]). Streams are
 * HLS played through NetWix's server-side proxy ([App\Http\Controllers\StreamController]) — same
 * KIND_HLS path as the anime108 / 24-hdx / goseries4k sources, no new player infra.
 *
 * Chain (all confirmed server-side, plain GETs — no auth / nonce / admin-ajax):
 *   1. catalogue → scrape /anime/ + /movies/ archive pages (28 items/page): each <article> gives the
 *      permalink (→ type: /anime/=series, /movies/=movie), Thai title, poster and dub badge.
 *   2. episodes  → GET /anime/{slug}/ → ul.episodios li .episodiotitle a href="/ep/{ep-slug}/" in order.
 *      A movie has no episode list — its single episode resolves the movie page's own player.
 *   3. resolve   → GET the ep (or movie) page → Dooplay #playeroptionsul <li data-post data-type data-nume>.
 *      GET /wp-json/dooplayer/v2/{post}/{tv|movie}/{nume} → {embed_url}. Pick the animemami.xyz server
 *      (nume 1, the proxyable one; abyssplayer/ok.ru mirrors are skipped). GET that embed → its
 *      Inertia data-page JSON carries props.video.url = a maimeorder ".txt" HLS manifest (Referer
 *      animemami.xyz REQUIRED). The manifest is JSON-base64-wrapped ({"p":base64(#EXTM3U…)}) with
 *      segments disguised as .webp but real MPEG-TS — [App\Support\HlsManifest] unwraps the envelope
 *      and [App\Support\HlsSegment] is a no-op on the already-clean TS. See the recon note in BrainX.
 */
class AnimerukaSource implements MediaSource, ProvidesGenres, ProvidesPoster, ProvidesSynopsis
{
    public const BASE = 'https://animeruka.com';

    /** animeruka Dooplay genre slugs (English) → NetWix genre names (mirrors the anime108 map). */
    private const GENRE_MAP = [
        'action' => 'แอ็กชัน', 'martial-arts' => 'แอ็กชัน', 'super-power' => 'แอ็กชัน', 'samurai' => 'แอ็กชัน',
        'shounen' => 'แอ็กชัน', 'military' => 'แอ็กชัน', 'sports' => 'แอ็กชัน',
        'adventure' => 'ผจญภัย', 'isekai' => 'ผจญภัย',
        'comedy' => 'ตลก', 'parody' => 'ตลก', 'school' => 'ตลก',
        'drama' => 'ดราม่า', 'slice-of-life' => 'ดราม่า', 'seinen' => 'ดราม่า', 'josei' => 'ดราม่า',
        'fantasy' => 'แฟนตาซี & ไซไฟ', 'sci-fi' => 'แฟนตาซี & ไซไฟ', 'magic' => 'แฟนตาซี & ไซไฟ',
        'supernatural' => 'แฟนตาซี & ไซไฟ', 'mecha' => 'แฟนตาซี & ไซไฟ', 'space' => 'แฟนตาซี & ไซไฟ',
        'romance' => 'โรแมนติก', 'harem' => 'โรแมนติก', 'shoujo' => 'โรแมนติก', 'ecchi' => 'โรแมนติก',
        'horror' => 'สยองขวัญ', 'demons' => 'สยองขวัญ', 'vampire' => 'สยองขวัญ', 'thriller' => 'สยองขวัญ',
        'mystery' => 'อาชญากรรม', 'detective' => 'อาชญากรรม', 'psychological' => 'อาชญากรรม',
        'suspense' => 'อาชญากรรม', 'police' => 'อาชญากรรม',
    ];

    /** animemami is the only proxyable server; its maimeorder manifest + segments are gated on this Referer. */
    private const PLAYER_HOST = 'animemami.xyz';

    private const PLAYER_ORIGIN = 'https://animemami.xyz/';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** De-dupe key set across the two archives within one catalogue run (a movie can surface on /anime/ too). */
    private array $seen = [];

    public function id(): string
    {
        return 'animeruka';
    }

    public function displayName(): string
    {
        return 'AnimeRuka (การ์ตูน/อนิเมะ)';
    }

    public function defaultContentType(): string
    {
        return 'series';
    }

    public function isProgressive(): bool
    {
        return false;   // HLS — streams through the server proxy, no stored preview file
    }

    public function umbrellaGenre(): ?string
    {
        return 'อนิเมะ';   // every title filed under the anime umbrella, same as anime108
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => self::UA,
            'Accept-Language' => 'th,en;q=0.8',
            'Referer' => self::BASE.'/',
        ])->timeout(60)->retry(2, 400);
    }

    // --------------------------------------------------------- catalogue

    /**
     * Scrape both Dooplay archives (/anime/ = series, /movies/ = films), emitting per page so a timeout
     * keeps the earlier pages. Page 1 is the bare archive; page N≥2 is /{root}/page/N/. Stops a root
     * when a page yields no items. Classified by permalink, de-duped across roots within the run.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int
    {
        $this->seen = [];
        $total = 0;

        foreach (['anime', 'movies'] as $root) {
            for ($page = 1; $page <= $maxPages; $page++) {
                $url = $page === 1 ? self::BASE."/{$root}/" : self::BASE."/{$root}/page/{$page}/";
                try {
                    $resp = $this->http()->get($url);
                } catch (\Throwable) {
                    break;
                }
                if (! $resp->ok()) {
                    break;
                }

                $items = $this->parseArchive($resp->body());
                if ($items === []) {
                    break;   // past the last page for this root
                }

                $onBatch($items);
                $total += count($items);
            }
        }

        return $total;
    }

    /**
     * Parse an archive page's <article> cards into RemoteSeries. Type comes from the permalink
     * (/anime/ → series, /movies/ → movie), so a movie that leaks into the "latest" /anime/ grid is
     * still classified right; sourceKey = the permalink path ("anime/{slug}" | "movies/{slug}"), which
     * is unique and doubles as the fetch path for episodes/resolve.
     *
     * @return RemoteSeries[]
     */
    private function parseArchive(string $html): array
    {
        if (! preg_match_all('~<article\s+id="post-\d+".*?</article>~is', $html, $arts)) {
            return [];
        }

        $items = [];
        foreach ($arts[0] as $art) {
            // The theme mixes quote styles (archive links are double-quoted, the episodios list is
            // single-quoted) — accept either for every attribute we scrape.
            if (! preg_match('~href=[\'"]'.preg_quote(self::BASE, '~').'/(anime|movies)/([a-z0-9-]+)/[\'"]~i', $art, $h)) {
                continue;
            }
            $isMovie = strtolower($h[1]) === 'movies';
            $slug = $h[2];
            $key = $h[1].'/'.$slug;
            if (isset($this->seen[$key])) {
                continue;
            }
            $this->seen[$key] = true;

            $rawTitle = preg_match('~<div class="movie-title">(.*?)</div>~is', $art, $t)
                ? trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : $slug;
            $poster = preg_match('~<img[^>]*\ssrc=[\'"]([^\'"]+)[\'"]~i', $art, $p) ? html_entity_decode($p[1], ENT_QUOTES) : null;
            $dubBadge = preg_match('~<span class="features-type">(.*?)</span>~is', $art, $d) ? strip_tags($d[1]) : $rawTitle;

            $items[] = new RemoteSeries(
                source: $this->id(),
                sourceKey: $key,
                title: $rawTitle,
                cleanTitle: $this->cleanTitle($rawTitle),
                posterUrl: $poster,
                year: null,
                dubType: $this->detectDub($dubBadge),
                extra: [
                    'slug' => $slug,
                    'is_movie' => $isMovie,
                    'genre_names' => [],   // Dooplay archive has no per-item genres → keyword-guess + umbrella
                ],
            );
        }

        return $items;
    }

    /** Strip the Thai/dub tail off the mixed romaji+Thai title → a cleaner display/dedupe name. */
    private function cleanTitle(string $raw): string
    {
        $t = trim($raw);
        $t = preg_replace('~\s*(ซับไทย|พากย์ไทย|ซับ|พากย์)\s*$~u', '', $t) ?? $t;
        $t = preg_replace('~\s*(ตอนที่|ตอนล่าสุด|EP\.?\s*\d).*$~ui', '', $t) ?? $t;

        return trim($t) !== '' ? trim($t) : $raw;
    }

    private function detectDub(string $badge): ?string
    {
        // A title tagged both ("ซับไทย พากย์ไทย") is treated as dubbed.
        if (str_contains($badge, 'พากย์ไทย')) {
            return 'thai_dub';
        }
        if (str_contains($badge, 'ซับไทย')) {
            return 'thai_sub';
        }

        return null;
    }

    // --------------------------------------------------------- episodes

    /**
     * Episodes = the ul.episodios list on the series page, in ep-number order. A movie (no episode
     * list) exposes one episode whose ref IS the movie path, so resolveByRef plays the movie's own
     * player.
     *
     * @return array<int,array{number:int,ref:string}>
     */
    public function fetchEpisodes(RemoteSeries $series): array
    {
        if (! empty($series->extra['is_movie'])) {
            return [['number' => 1, 'ref' => $series->sourceKey]];   // sourceKey = "movies/{slug}"
        }

        $html = $this->fetchPage(self::BASE.'/'.trim($series->sourceKey, '/').'/');
        if ($html === null) {
            return [['number' => 1, 'ref' => $series->sourceKey]];
        }

        $eps = $this->parseEpisodes($html);

        return $eps !== [] ? $eps : [['number' => 1, 'ref' => $series->sourceKey]];
    }

    /**
     * Pull /ep/{slug}/ links (+ their episode number) out of a series page's ul.episodios, de-duped and
     * sorted ascending. Number comes from the "-ep-N" slug tail, else the "ตอนที่ N" numerando, else
     * document order.
     *
     * @return array<int,array{number:int,ref:string}>
     */
    private function parseEpisodes(string $html): array
    {
        if (! preg_match('~<ul[^>]*class=[\'"][^\'"]*episodios[^\'"]*[\'"][^>]*>(.*?)</ul>~is', $html, $ul)) {
            return [];
        }
        if (! preg_match_all('~<li\b.*?</li>~is', $ul[1], $lis)) {
            return [];
        }

        $eps = [];
        $seen = [];
        foreach ($lis[0] as $i => $li) {
            if (! preg_match('~href=[\'"]'.preg_quote(self::BASE, '~').'/ep/([a-z0-9-]+)/[\'"]~i', $li, $m)) {
                continue;
            }
            $slug = $m[1];
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            if (preg_match('~-ep-?(\d+)$~i', $slug, $n)) {
                $num = (int) $n[1];
            } elseif (preg_match('~numerando[\'"]?>\s*[^\d]*(\d+)~u', $li, $n)) {
                $num = (int) $n[1];
            } else {
                $num = $i + 1;
            }

            $eps[$num] = ['number' => $num, 'ref' => 'ep/'.$slug];
        }

        ksort($eps);

        return array_values($eps);
    }

    // --------------------------------------------------------- synopsis

    public function fetchSynopsis(RemoteSeries $series): ?string
    {
        $html = $this->fetchPage(self::BASE.'/'.trim($series->sourceKey, '/').'/');

        return $html !== null ? SynopsisScraper::fromHtml($html) : null;
    }

    /** Re-fetch a fresh poster from the title page's og:image (heals a dead hotlink). */
    public function fetchPoster(RemoteSeries $series): ?string
    {
        $html = $this->fetchPage(self::BASE.'/'.trim($series->sourceKey, '/').'/');

        return $html !== null ? PosterScraper::fromHtml($html) : null;
    }

    /**
     * Scrape the title page's own genre tags (`<a href="…/genre/{slug}/" rel="tag">`) — the per-title
     * genres the Dooplay archive doesn't expose — and map them to NetWix genre names. rel="tag" isolates
     * the title's genres from the site-wide genre nav.
     */
    public function fetchGenres(RemoteSeries $series): array
    {
        $html = $this->fetchPage(self::BASE.'/'.trim($series->sourceKey, '/').'/');
        if ($html === null || ! preg_match_all('~/genre/([a-z0-9-]+)/[\'"][^>]*\brel=[\'"]?tag~i', $html, $m)) {
            return [];
        }
        $names = [];
        foreach (array_unique($m[1]) as $slug) {
            if (isset(self::GENRE_MAP[strtolower($slug)])) {
                $names[] = self::GENRE_MAP[strtolower($slug)];
            }
        }

        return array_values(array_unique($names));
    }

    // --------------------------------------------------------- resolve

    /**
     * Resolve one episode's HLS stream. $sourceRef is the page path to the ep ("ep/{slug}") or, for a
     * movie/one-shot, the title path itself. The page's Dooplay player options resolve through
     * dooplayer/v2 to the animemami embed, whose Inertia JSON exposes the maimeorder .txt manifest.
     */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream
    {
        $path = trim($sourceRef !== '' ? $sourceRef : $sourceKey, '/');
        $html = $this->fetchPage(self::BASE.'/'.$path.'/');
        if ($html === null) {
            return null;
        }

        [$postId, $type, $numes] = $this->playerOptions($html);
        if ($postId === null) {
            return null;   // no Dooplay player on the page (removed / not ready) → caller shows "preparing"
        }

        foreach ($numes as $nume) {
            $embed = $this->dooplayerEmbed($postId, $type, $nume);
            if ($embed === null || ! str_contains($embed, self::PLAYER_HOST)) {
                continue;   // abyssplayer / ok.ru mirror — not proxyable, try the next server
            }
            $url = $this->animemamiStream($embed);
            if ($url !== null) {
                $kind = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.mp4')
                    ? RemoteStream::KIND_MP4 : RemoteStream::KIND_HLS;

                return new RemoteStream($kind, $url, self::PLAYER_ORIGIN);
            }
        }

        return null;
    }

    /**
     * Read the Dooplay #playeroptionsul: the shared post id + type ("tv"|"movie") and the numeric
     * server numes in document order (nume 1 = the primary animemami server). Non-numeric numes
     * (a "trailer") are dropped.
     *
     * @return array{0:?string,1:string,2:int[]}
     */
    private function playerOptions(string $html): array
    {
        if (! preg_match_all('~<li[^>]*\bdooplay_player_option\b[^>]*>~i', $html, $lis)) {
            return [null, 'tv', []];
        }

        $postId = null;
        $type = 'tv';
        $numes = [];
        foreach ($lis[0] as $li) {
            if ($postId === null && preg_match('~data-post=[\'"](\d+)[\'"]~i', $li, $p)) {
                $postId = $p[1];
            }
            if (preg_match('~data-type=[\'"](tv|movie)[\'"]~i', $li, $t)) {
                $type = strtolower($t[1]);
            }
            if (preg_match('~data-nume=[\'"](\d+)[\'"]~i', $li, $n)) {
                $numes[] = (int) $n[1];
            }
        }

        sort($numes);   // try server 1 (animemami) first

        return [$postId, $type, array_values(array_unique($numes))];
    }

    /** GET dooplayer/v2/{post}/{type}/{nume} → the embed_url for that server, or null. */
    private function dooplayerEmbed(string $postId, string $type, int $nume): ?string
    {
        try {
            $json = $this->http()->get(self::BASE."/wp-json/dooplayer/v2/{$postId}/{$type}/{$nume}")->json();
        } catch (\Throwable) {
            return null;
        }
        $url = is_array($json) ? ($json['embed_url'] ?? null) : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * The animemami embed page is an Inertia app: its `data-page` attribute holds JSON whose
     * props.video.url is the real (maimeorder) stream. Fetching it needs the animeruka Referer; the
     * returned .txt then needs the animemami Referer (added by the proxy from RemoteStream::referer).
     */
    private function animemamiStream(string $embedUrl): ?string
    {
        $html = $this->fetchPage($embedUrl);
        if ($html === null || ! preg_match('~data-page="([^"]+)"~', $html, $m)) {
            return null;
        }

        $json = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        $url = $json['props']['video']['url'] ?? null;

        return is_string($url) && str_starts_with($url, 'http') ? $url : null;
    }

    /** GET a page with the site Referer; returns the HTML, or null on failure / empty body. */
    private function fetchPage(string $url): ?string
    {
        try {
            $body = $this->http()->get($url)->body();
        } catch (\Throwable) {
            return null;
        }

        return $body !== '' ? $body : null;
    }
}
