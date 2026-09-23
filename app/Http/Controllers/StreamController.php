<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\HlsManifest;
use App\Support\HlsSegment;
use App\Support\MirrorLink;
use App\Support\MirrorRotation;
use App\Support\PlaybackHealth;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proxies imported streams through our origin so the browser can play them:
 *  - HLS (wow-drama / getplay-cdn): rewrites the playlist so segments come back through us,
 *    and each segment has its leading PNG header stripped down to the MPEG-TS sync byte.
 *  - MP4 (rongyok / Discord CDN): pass-through with Range support (keeps the expiring URL hidden).
 * Segment URLs are HMAC-signed so this can't be used as an open proxy (SSRF).
 */
class StreamController extends Controller
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** Manifest-token lifetime (seconds). Long enough for one sitting, short enough that a scraped
     *  URL stops working the same day. */
    private const TTL = 21600; // 6h

    /**
     * Segment URLs are minted per 3-hour bucket and stay IDENTICAL for every viewer inside it.
     *
     * They used to be sealed with a random IV and an expiry of "now + 6h", so every manifest rebuild
     * (every 10 minutes) produced a brand-new set of URLs. Cloudflare keys its cache on the full URL,
     * so two people watching the same episode more than ten minutes apart never shared a single
     * cached segment — measured 2026-09-23, practically every segment was a MISS that our PHP proxied.
     * A URL minted at the start of a bucket lives two buckets, one minted at its end lives one: 3h–6h,
     * so a scraped URL still dies the same day, exactly as before.
     */
    private const SEAL_BUCKET = 10800; // 3h

    /**
     * Wall-clock ceiling for one segment request, every upstream attempt included (seconds).
     *
     * segment() buffers the whole upstream body before it answers, so the PHP-FPM worker is held for
     * as long as the upstream takes. Three attempts of connectTimeout(8)+timeout(30) let one hung CDN
     * hold a worker for ~115s — and that worker comes from a pool shared with every other site on the
     * box, which kept hitting its ceiling at ~2 req/s (2026-09-22). A healthy segment takes 2-4s. Past
     * this budget we answer 502 and hls.js retries on its own: it waits 60s per fragment and retries 8
     * times (resources/js/app.js), so the player loses nothing it wasn't already covering.
     */
    private const SEGMENT_BUDGET = 20;

    /** No single attempt may take the whole budget, so a hang still leaves room for one retry. */
    private const SEGMENT_ATTEMPT_TIMEOUT = 12;

    public function manifest(Episode $episode, Request $request, SourceRegistry $registry)
    {
        // A NESTED playlist (a variant or an audio rendition of a master this route already served)
        // arrives with its own sealed handle. Only we can mint one, so — exactly like a segment URL —
        // it authorises itself, and its URL stays the same for every viewer (no per-viewer token).
        // The episode's own top-level playlist still requires the short-lived token minted by the
        // (authenticated) resolver: episode ids are sequential, so without it anyone could enumerate
        // them and hotlink our streams with no account. Web player and app both get it from
        // EpisodeSourceController, so neither needs to send cookies here.
        $nested = $this->nestedHandle($episode, $request);
        if ($nested === null) {
            abort_unless($this->tokenOk($episode, (string) $request->query('t', '')), 403);
        }
        $this->blockForeignEmbed($request);

        // NB: no gateAdult() here — this route is cookieless (so Cloudflare can cache it) and has no
        // session/auth. The Pro/adult gate is enforced upstream in EpisodeSourceController::resolve,
        // which is the ONLY thing that mints a manifest token, so an unentitled viewer never gets here.
        $out = $nested !== null
            ? $this->nestedPlaylist($episode, $nested)
            : $this->topLevelPlaylist($episode, $registry);

        return response($out, 200)->withHeaders([
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * The episode's own playlist, rewritten through us and cached. Public so the resolver can build it
     * the moment it hands out the manifest URL — the player's own request then finds it ready (or waits
     * on the build lock) instead of starting the upstream fetch from scratch. Aborts (404/504) exactly
     * as the route would.
     */
    public function topLevelPlaylist(Episode $episode, SourceRegistry $registry): string
    {
        $resolved = $this->resolveWithLink($episode, $registry);
        $stream = $resolved['stream'] ?? null;
        $link = $resolved['link'] ?? null;
        if (! $stream || $stream->kind !== RemoteStream::KIND_HLS) {
            // Upstream link is dead — count this viewer toward auto-suspend (see PlaybackHealth).
            if ($episode->content) {
                PlaybackHealth::recordFailure($episode->content, PlaybackHealth::viewer(), 'no_source');
            }
            abort(404);
        }

        return $this->cachedPlaylist("ep_manifest:{$episode->id}", fn () => $this->rewrite($episode, $stream, $link, false));
    }

    /**
     * A child playlist of a master. The Referer rides *inside* the sealed handle, not beside it on the
     * query string — reading it from `?r=` meant every child of a master was fetched with no Referer at
     * all: getplay-cdn answers those 403, and a source that checks Referer and serves a master
     * (wowdrama) was 100% dead while looking healthy at every other layer.
     *
     * @param  array{0:string,1:?string,2:int}  $nested
     */
    private function nestedPlaylist(Episode $episode, array $nested): string
    {
        [$url, $referer] = $nested;
        $stream = new RemoteStream(RemoteStream::KIND_HLS, $url, $referer);

        return $this->cachedPlaylist("ep_manifest:{$episode->id}:".sha1($url), fn () => $this->rewrite($episode, $stream, null, true));
    }

    /**
     * Rewriting the upstream playlist means fetching a big (100k+) manifest and sealing every one of its
     * ~700 segment URLs — slow (seconds) on a cold hit. Cache the finished playlist briefly so re-plays
     * and seeks start instantly. One build at a time per playlist: the resolver warms it while the
     * player is still loading, and the player's request should wait for that build, not start another.
     */
    private function cachedPlaylist(string $key, \Closure $build): string
    {
        $hit = Cache::get($key);
        if (is_string($hit)) {
            return $hit;
        }
        try {
            return Cache::lock($key.':build', 45)->block(12, fn () => Cache::remember($key, now()->addMinutes(10), $build));
        } catch (LockTimeoutException) {
            return Cache::remember($key, now()->addMinutes(10), $build);   // a stuck build must not stall play
        }
    }

    /** Fetch one upstream playlist and rewrite every URI in it to come back through us. */
    private function rewrite(Episode $episode, RemoteStream $stream, ?MirrorLink $link, bool $nested): string
    {
        // A dead/slow upstream (e.g. a rotated tiktokcdn link) must not bubble up as an uncaught
        // ConnectionException — that spammed the ERROR log. Fail fast on connect, return a clean 504.
        try {
            $resp = Http::withHeaders($this->headers($stream->referer))->connectTimeout(8)->timeout(30)->get($stream->url);
        } catch (\Throwable $e) {
            abort(504, 'upstream manifest unavailable');
        }
        // Some players wrap the playlist (animeruka/animemami serves it as JSON-base64 in a .txt) —
        // normalise that to a raw #EXTM3U body before the checks + rewrite below.
        $body = HlsManifest::unwrap($resp->body());

        // Resolving can "succeed" yet hand back a dead link: some sources (getplay-cdn's token gate, an
        // expired signed URL) answer the manifest fetch with a short "Access Denied" (HTTP 403) instead
        // of a playlist. Rewriting that produces a 200 with junk segment URLs and the player just
        // freezes — and, because resolve() didn't fail, PlaybackHealth never hears about it. So when the
        // body isn't a real playlist (every valid HLS manifest starts with #EXTM3U), treat it as a
        // playback failure and hand the viewer a clean 404.
        //
        // BUT only when the upstream itself said the link is bad (a definitive 2xx-junk or 4xx). A 5xx
        // is the CDN having a transient moment (e.g. getplay 502/504) — that must NOT count toward
        // auto-suspend, or a brief upstream outage would mass-suspend the whole catalogue.
        if (! str_contains($body, '#EXTM3U')) {
            if ($resp->serverError()) {
                abort(504, 'upstream manifest unavailable');   // transient — no failure recorded
            }
            // This link resolved but doesn't actually play. Bench it so the next request rotates on to
            // the next link in the chain instead of re-serving the same dead one.
            if ($link !== null) {
                MirrorRotation::markDead($episode, $link);
            }
            if ($episode->content) {
                PlaybackHealth::recordFailure($episode->content, PlaybackHealth::viewer(), 'dead_manifest');
            }
            abort(404);   // thrown inside Cache::remember → the junk is never cached
        }
        $dir = $this->baseUrl($stream->url);
        $lines = preg_split('/\r?\n/', $body);

        // The context carries what every child URI has in common, so each line seals only what
        // differs. The playlist's own directory is not that: 24hdx serves its segments from another
        // host, 124 characters each with 113 of them shared, and sealing the whole URL on every line
        // kept a 561-segment playlist at 86 KB gzipped when the part that changes is 11 characters.
        $base = self::sharedPrefix(array_map(
            fn (string $l) => $this->absolute($l, $dir),
            array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== '' && $l[0] !== '#')),
        ), $dir);

        // A MASTER playlist lists variant streams + alternate renditions; a MEDIA playlist lists
        // segments. That one fact decides how every child URI is rewritten — children of a master are
        // playlists (re-proxy through this route), children of a media playlist are segments. Don't
        // test the .m3u8 extension: hd432's renditions have no extension at all. HLS allows exactly two
        // levels (master → media); a third would mean a self-referencing playlist, so a nested playlist
        // is never rewritten as a master rather than proxying in a circle.
        $isMaster = str_contains($body, '#EXT-X-STREAM-INF') && ! $nested;

        // One sealed context per playlist — expiry, shared prefix and Referer — the same on every line,
        // so the repeated part of each line is identical and compresses to almost nothing.
        $ctx = self::sivSeal('c|'.$episode->id, self::bucketExpiry().'|'.$base.'|'.(string) $stream->referer);
        $child = fn (string $uri) => $this->childUrl($episode, $ctx, $base, $this->absolute($uri, $dir), $isMaster);

        return collect($lines)->map(function (string $line) use ($child) {
            $trim = trim($line);
            if ($trim === '') {
                return $line;
            }
            // URIs inside tags: #EXT-X-MEDIA renditions (master) vs #EXT-X-KEY / #EXT-X-MAP (media).
            if (str_starts_with($trim, '#')) {
                return preg_replace_callback('/URI="([^"]+)"/', fn ($m) => 'URI="'.$child($m[1]).'"', $line);
            }

            return $child($trim);
        })->implode("\n");
    }

    public function segment(Episode $episode, Request $request)
    {
        // The manifest and the mp4 route already refuse a foreign embed; segments were the gap. A
        // stolen manifest is only useful if its segments load, so the rule has to hold on both.
        $this->blockForeignEmbed($request);

        $opened = $this->openHandle($episode, $request);
        abort_if($opened === null, 403);
        [$url, $ref, $exp] = $opened;

        // Retry a transient upstream hiccup a couple of times before giving up — one failed segment
        // shouldn't be enough to stall the whole stream (there's no lower rendition to fall back to).
        // A fast failure (a 5xx, a reset) still gets its retries; a slow one is cut off at the budget.
        $deadline = now()->getTimestamp() + self::SEGMENT_BUDGET;
        $resp = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $left = $deadline - now()->getTimestamp();
            if ($left < 3) {
                break;   // too little budget left for an attempt that could actually finish
            }
            try {
                $resp = Http::withHeaders($this->headers($ref ?: null))
                    ->connectTimeout(min(5, $left))
                    ->timeout(min(self::SEGMENT_ATTEMPT_TIMEOUT, $left))
                    ->get($url);
                if ($resp->ok()) {
                    break;
                }
            } catch (\Throwable $e) {
                $resp = null;
            }
            usleep(250000);   // 250ms backoff between attempts
        }
        abort_unless($resp && $resp->ok(), 502);

        // Strip any fake-image wrapper (torbo007's tiktokcdn PNGs, getplay-cdn) down to the TS payload.
        $data = HlsSegment::stripToTsSync($resp->body());

        // Cloudflare edge-caches this for max-age (Cache Rule on /stream/*/segment). The signature dies
        // at $exp, so a cached copy must not outlive it: at a flat 86400 a scraped URL kept playing from
        // the edge for up to 18h after it was meant to stop working.
        $maxAge = max(0, min(2 * self::SEAL_BUCKET, $exp - now()->getTimestamp()));

        return response($data, 200)->withHeaders([
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => "public, max-age={$maxAge}",
        ]);
    }

    public function mp4(Episode $episode, Request $request, SourceRegistry $registry): StreamedResponse
    {
        // Same gate as manifest(): a resolver-minted token + no foreign embed. Without it the mp4 proxy
        // was a hole straight around the m3u8 hardening — episode ids are sequential, so anyone could
        // enumerate /stream/{id}/video.mp4 and hotlink our streams with no account, and for an HLS title
        // it streamed the RAW upstream .m3u8, exposing the real CDN segment URLs. The hero preview and
        // admin form now mint this token; the app never hits this route (it gets the direct mp4 url).
        abort_unless($this->tokenOk($episode, (string) $request->query('t', '')), 403);
        $this->blockForeignEmbed($request);

        $this->gateAdult($episode);
        $stream = $this->resolve($episode, $registry);
        if (! $stream) {
            if ($episode->content) {
                PlaybackHealth::recordFailure($episode->content, PlaybackHealth::viewer(), 'no_source');
            }
            abort(404);
        }

        // This route serves PROGRESSIVE files only. An HLS stream must go through manifest() (which
        // proxies + rewrites its segments); streaming its raw upstream .m3u8 here would leak the real
        // CDN URLs and bypass the segment proxy entirely.
        if ($stream->kind === RemoteStream::KIND_HLS
            || str_ends_with(strtolower((string) parse_url($stream->url, PHP_URL_PATH)), '.m3u8')) {
            abort(404);
        }

        $range = $request->header('Range');
        try {
            $upstream = Http::withHeaders(array_filter([
                'User-Agent' => self::UA,
                'Referer' => $stream->referer,
                'Range' => $range,
            ]))->withOptions(['stream' => true])->connectTimeout(8)->timeout(60)->get($stream->url);
        } catch (\Throwable $e) {
            abort(504, 'upstream video unavailable');   // dead/rotated CDN link — clean 504, no ERROR-log spam
        }

        $status = $upstream->status();
        $headers = array_filter([
            'Content-Type' => $upstream->header('Content-Type') ?: 'video/mp4',
            'Content-Length' => $upstream->header('Content-Length') ?: null,
            'Content-Range' => $upstream->header('Content-Range') ?: null,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store',
        ]);

        return response()->stream(function () use ($upstream) {
            $stream = $upstream->toPsrResponse()->getBody();
            while (! $stream->eof()) {
                echo $stream->read(1024 * 256);
                flush();
            }
        }, $status, $headers);
    }

    // ------------------------------------------------------------- helpers

    /**
     * This proxy is public (guests + the app stream non-adult content without a session). Adult
     * (18+/20+) titles, however, are Pro-only — so block them here too, or the gate on the web player
     * and the resolver could be side-stepped by hitting the proxy directly with an episode id.
     */
    private function gateAdult(Episode $episode): void
    {
        $content = $episode->content;
        if ($content && $content->requires_pro && ! auth()->user()?->isProMember()) {
            abort(403);
        }
    }

    /**
     * A stored/mirrored file plays straight from us; everything else goes through the link rotation
     * ([MirrorRotation]), which walks the forced backup → last-working link → own source → mirrors and
     * declares the title dead once the whole cycle has failed.
     */
    private function resolve(Episode $episode, SourceRegistry $registry): ?RemoteStream
    {
        return $this->resolveWithLink($episode, $registry)['stream'] ?? null;
    }

    /**
     * As [self::resolve], but also says WHICH link produced the stream so the caller can bench it if
     * the playlist turns out to be junk. A stored/mirrored file has no link (nothing to rotate to).
     *
     * @return array{stream:?RemoteStream,link:?\App\Support\MirrorLink}
     */
    private function resolveWithLink(Episode $episode, SourceRegistry $registry): array
    {
        if (! $episode->source || ! $episode->source_ref) {
            $stream = $episode->video_url
                ? new RemoteStream(str_contains($episode->video_url, '.m3u8') ? RemoteStream::KIND_HLS : RemoteStream::KIND_MP4, $episode->video_url)
                : null;

            return ['stream' => $stream, 'link' => null];
        }

        return MirrorRotation::resolve($episode, $registry) ?? ['stream' => null, 'link' => null];
    }

    private function headers(?string $referer): array
    {
        return array_filter([
            'User-Agent' => self::UA,
            'Accept' => '*/*',
            'Referer' => $referer,
        ]);
    }

    /**
     * The URL a rewritten playlist line points at: a segment, or — for the children of a master — a
     * nested playlist back through manifest(). RELATIVE on purpose: every player resolves it against
     * the playlist it came from, so the ~30 bytes of scheme + host + /stream/{id}/ are not repeated on
     * every one of ~700 lines.
     *
     * The line carries the playlist's sealed context `c` (identical on every line) plus its own sealed
     * remainder `p`, bound to that context. When the upstream URL lives under the playlist's base — the
     * usual case — only the tail after the base is sealed, which is what keeps `p` short.
     */
    private function childUrl(Episode $episode, string $ctx, string $base, string $abs, bool $playlist): string
    {
        $rest = str_starts_with($abs, $base) ? 'r'.substr($abs, strlen($base)) : 'a'.$abs;
        $query = ['c' => $ctx, 'p' => self::sivSeal('p|'.$episode->id.'|'.$ctx, $rest)];

        return $playlist
            ? 'index.m3u8?'.http_build_query($query + ['d' => 1])   // d: a nested playlist never nests again
            : 'segment?'.http_build_query($query);
    }

    /**
     * The sealed handle on a nested-playlist request, opened — or null when this is a plain top-level
     * manifest request. A handle that does not open is refused outright: a caller can only ask for a
     * sub-playlist we ourselves emitted, never an arbitrary URL (SSRF).
     *
     * @return array{0:string,1:?string,2:int}|null  [url, referer, expiry]
     */
    private function nestedHandle(Episode $episode, Request $request): ?array
    {
        if (! $request->filled('p') && ! $request->filled('u')) {
            return null;
        }
        $opened = $this->openHandle($episode, $request);
        abort_if($opened === null, 403);

        return $opened;
    }

    /**
     * Open the handle on a segment / nested-playlist request. Returns [url, referer, expiry], or null
     * when it is forged, corrupt, expired, or was minted for another episode.
     *
     * Nothing about the upstream ever leaves this server: before 2026-08-21 the manifest carried the
     * source's CDN address and the Referer it needs in the clear, which handed anyone who pressed play
     * our whole supply chain — and told the source who was reselling it.
     *
     * @return array{0:string,1:?string,2:int}|null
     */
    private function openHandle(Episode $episode, Request $request): ?array
    {
        $c = (string) $request->query('c', '');
        $p = (string) $request->query('p', '');
        if ($c === '' || $p === '') {
            return $this->openLegacyHandle((string) $request->query('u', ''));
        }

        $ctx = self::sivOpen('c|'.$episode->id, $c);
        $parts = $ctx === null ? [] : explode('|', $ctx, 3);
        if (count($parts) !== 3 || (int) $parts[0] < now()->getTimestamp()) {
            return null;
        }
        [$exp, $base, $referer] = $parts;

        $rest = self::sivOpen('p|'.$episode->id.'|'.$c, $p);
        $url = match ($rest === null ? '' : $rest[0]) {
            'r' => $base.substr($rest, 1),
            'a' => substr($rest, 1),
            default => '',
        };
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        return [$url, $referer !== '' ? $referer : null, (int) $exp];
    }

    /**
     * Handles minted before the stable format (`u` = Crypt::encryptString("exp|url|referer")). A player
     * already mid-episode holds URLs like this for up to six hours after a deploy; refusing them would
     * break every stream in flight. Nothing mints them any more — delete this after 2026-09-30.
     *
     * @return array{0:string,1:?string,2:int}|null
     */
    private function openLegacyHandle(string $u): ?array
    {
        if ($u === '') {
            return null;
        }
        try {
            $parts = explode('|', Crypt::decryptString($u), 3);
        } catch (\Throwable) {
            return null;
        }
        if (count($parts) !== 3 || (int) $parts[0] < now()->getTimestamp() || ! str_starts_with($parts[1], 'https://')) {
            return null;
        }

        return [$parts[1], $parts[2] !== '' ? $parts[2] : null, (int) $parts[0]];
    }

    /**
     * The longest prefix every one of $urls starts with — or $fallback when there is nothing useful to
     * share (no URIs, or not even a scheme in common). Deterministic for a given playlist, so the
     * context it goes into stays the same across rebuilds, which is what keeps the URLs stable.
     *
     * @param  list<string>  $urls
     */
    private static function sharedPrefix(array $urls, string $fallback): string
    {
        if ($urls === []) {
            return $fallback;
        }
        $prefix = $urls[0];
        foreach ($urls as $url) {
            $len = min(strlen($prefix), strlen($url));
            $i = 0;
            while ($i < $len && $prefix[$i] === $url[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') {
                break;
            }
        }

        return str_starts_with($prefix, 'https://') && strlen($prefix) > strlen('https://') ? $prefix : $fallback;
    }

    /** End of the NEXT bucket: a URL minted now lives between one and two buckets (3h–6h). */
    private static function bucketExpiry(): int
    {
        return (intdiv(now()->getTimestamp(), self::SEAL_BUCKET) + 2) * self::SEAL_BUCKET;
    }

    /**
     * Deterministic authenticated encryption (SIV construction): the IV is an HMAC of the context and
     * the plaintext, so the same input always seals to the same handle — which is what lets Cloudflare
     * serve one cached segment to every viewer — while the only thing it reveals is that two handles
     * are equal. Opening re-derives the IV from the decrypted text, so any change to a single byte, or
     * a handle presented under another context (episode, playlist), fails. Keys are derived from
     * APP_KEY and kept apart from each other and from every other use of it.
     */
    private static function sivSeal(string $context, string $plaintext): string
    {
        [$enc, $mac] = self::sealKeys();
        $iv = substr(hash_hmac('sha256', $context."\0".$plaintext, $mac, true), 0, 16);
        $cipher = (string) openssl_encrypt($plaintext, 'aes-256-ctr', $enc, OPENSSL_RAW_DATA, $iv);

        return rtrim(strtr(base64_encode($iv.$cipher), '+/', '-_'), '=');
    }

    private static function sivOpen(string $context, string $handle): ?string
    {
        if ($handle === '' || strspn($handle, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_') !== strlen($handle)) {
            return null;
        }
        $bin = base64_decode(strtr($handle, '-_', '+/'), true);
        if ($bin === false || strlen($bin) < 17) {
            return null;
        }
        [$enc, $mac] = self::sealKeys();
        $iv = substr($bin, 0, 16);
        $plaintext = openssl_decrypt(substr($bin, 16), 'aes-256-ctr', $enc, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            return null;
        }
        $expected = substr(hash_hmac('sha256', $context."\0".$plaintext, $mac, true), 0, 16);

        return hash_equals($expected, $iv) ? $plaintext : null;
    }

    /** @return array{0:string,1:string} [encryption key, MAC key] */
    private static function sealKeys(): array
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return [hash_hmac('sha256', 'netwix:stream-seal:enc', $key, true), hash_hmac('sha256', 'netwix:stream-seal:mac', $key, true)];
    }

    /** HMAC over "app.key" — truncated so signed URLs stay short. */
    private static function sig(string $data): string
    {
        return substr(hash_hmac('sha256', $data, (string) config('app.key')), 0, 40);
    }

    /**
     * Short-lived manifest token, minted by EpisodeSourceController (the single, authenticated
     * resolver) and required by manifest(). Public so the resolver can call it.
     */
    public static function token(Episode $episode): string
    {
        $exp = now()->getTimestamp() + self::TTL;

        return $exp.'.'.self::sig('m|'.$episode->id.'|'.$exp);
    }

    private function tokenOk(Episode $episode, string $tok): bool
    {
        [$exp, $s] = array_pad(explode('.', $tok, 2), 2, '');

        return ctype_digit((string) $exp) && (int) $exp >= now()->getTimestamp()
            && hash_equals(self::sig('m|'.$episode->id.'|'.$exp), (string) $s);
    }

    /** Browsers embedding our player on another site send a foreign Referer → block. The native app
     *  and hls.js/segment fetches send none (or ours) → allowed (the token already gates them). */
    private function blockForeignEmbed(Request $request): void
    {
        $ref = (string) $request->headers->get('referer', '');
        // Anchored at the end of the host: unanchored, `https://netwix.online.evil.tld/` counted as us.
        if ($ref !== '' && ! preg_match('~^https?://(www\.)?netwix\.online(?::\d+)?(?:[/?#]|$)~i', $ref)) {
            abort(403);
        }
    }

    private function baseUrl(string $url): string
    {
        $p = parse_url($url);
        $path = $p['path'] ?? '/';

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').rtrim(dirname($path), '/').'/';
    }

    private function absolute(string $uri, string $base): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        $p = parse_url($base);
        // Protocol-relative "//host/path" (e.g. 9.9nung/fembed segments on //vh006.xyz) → inherit scheme.
        // Must be checked before the single-"/" case, which "//" also matches.
        if (str_starts_with($uri, '//')) {
            return ($p['scheme'] ?? 'https').':'.$uri;
        }
        if (str_starts_with($uri, '/')) {
            return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').$uri;
        }

        return $base.$uri;
    }
}
