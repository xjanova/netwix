<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\HlsManifest;
use App\Support\HlsSegment;
use App\Support\MirrorRotation;
use App\Support\PlaybackHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** Stream token + segment-signature lifetime (seconds). Long enough for one sitting, short
     *  enough that a scraped URL stops working the same day. */
    private const TTL = 21600; // 6h

    public function manifest(Episode $episode, Request $request, SourceRegistry $registry)
    {
        // Require a short-lived token minted by the (authenticated) resolver. Episode ids are
        // sequential, so without this anyone could enumerate them and hotlink our streams with no
        // account. The web player AND the app both receive this token from EpisodeSourceController,
        // so neither needs to send cookies here.
        abort_unless($this->tokenOk($episode, (string) $request->query('t', '')), 403);
        $this->blockForeignEmbed($request);

        // NB: no gateAdult() here — this route is cookieless (so Cloudflare can cache it) and has no
        // session/auth. The Pro/adult gate is enforced upstream in EpisodeSourceController::resolve,
        // which is the ONLY thing that mints a manifest token, so an unentitled viewer never gets here.
        //
        // `u` = a NESTED playlist (a variant or an audio rendition of a master this route already
        // served), signed exactly like a proxied segment so it can't be used as an open proxy. Without
        // it we're serving the episode's own top-level stream.
        $nested = $this->signedNestedUrl($episode, $request);
        $link = null;
        if ($nested !== null) {
            $stream = new RemoteStream(RemoteStream::KIND_HLS, $nested, ((string) $request->query('r', '')) ?: null);
            $cacheKey = "ep_manifest:{$episode->id}:".sha1($nested);
        } else {
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
            $cacheKey = "ep_manifest:{$episode->id}";
        }

        // Rewriting the upstream playlist means fetching a big (100k+) manifest and signing every one
        // of its ~700 segment URLs — slow (several seconds) on a cold hit. Cache the finished playlist
        // briefly so re-plays / seeks start instantly; segment signatures stay valid far longer.
        $out = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($episode, $stream, $request, $link) {
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

            // Resolving can "succeed" yet hand back a dead link: some sources (getplay-cdn's token
            // gate, an expired signed URL) answer the manifest fetch with a short "Access Denied"
            // (HTTP 403) instead of a playlist. Rewriting that produces a 200 with junk segment URLs
            // and the player just freezes — and, because resolve() didn't fail, PlaybackHealth never
            // hears about it. So when the body isn't a real playlist (every valid HLS manifest starts
            // with #EXTM3U), treat it as a playback failure and hand the viewer a clean 404.
            //
            // BUT only when the upstream itself said the link is bad (a definitive 2xx-junk or 4xx).
            // A 5xx is the CDN having a transient moment (e.g. getplay 502/504) — that must NOT count
            // toward auto-suspend, or a brief upstream outage would mass-suspend the whole catalogue.
            if (! str_contains($body, '#EXTM3U')) {
                if ($resp->serverError()) {
                    abort(504, 'upstream manifest unavailable');   // transient — no failure recorded
                }
                // This link resolved but doesn't actually play. Bench it so the next request rotates
                // on to the next link in the chain instead of re-serving the same dead one.
                if ($link !== null) {
                    MirrorRotation::markDead($episode, $link);
                }
                if ($episode->content) {
                    PlaybackHealth::recordFailure($episode->content, PlaybackHealth::viewer(), 'dead_manifest');
                }
                abort(404);   // thrown inside Cache::remember → the junk is never cached
            }
            $base = $this->baseUrl($stream->url);

            // A MASTER playlist lists variant streams + alternate renditions; a MEDIA playlist lists
            // segments. That one fact decides how every child URI is rewritten — children of a master
            // are playlists (re-proxy through this route), children of a media playlist are segments.
            // Don't test the .m3u8 extension: hd432's renditions have no extension at all.
            // HLS allows exactly two levels (master → media). A third would mean a self-referencing
            // playlist, so stop rewriting there rather than proxying in a circle.
            $isMaster = str_contains($body, '#EXT-X-STREAM-INF') && (int) $request->query('d', 0) < 1;
            $token = (string) $request->query('t', '');

            return collect(preg_split('/\r?\n/', $body))->map(function (string $line) use ($base, $episode, $stream, $isMaster, $token) {
                $trim = trim($line);
                if ($trim === '') {
                    return $line;
                }
                // URIs inside tags: #EXT-X-MEDIA renditions (master) vs #EXT-X-KEY (media playlist).
                if (str_starts_with($trim, '#')) {
                    return preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($base, $episode, $stream, $isMaster, $token) {
                        $abs = $this->absolute($m[1], $base);

                        return 'URI="'.($isMaster
                            ? $this->nestedManifestUrl($episode, $abs, $stream->referer, $token)
                            : $this->proxyUrl($episode, $abs, $stream->referer)).'"';
                    }, $line);
                }
                $abs = $this->absolute($trim, $base);

                return $isMaster
                    ? $this->nestedManifestUrl($episode, $abs, $stream->referer, $token)
                    : $this->proxyUrl($episode, $abs, $stream->referer);
            })->implode("\n");
        });

        return response($out, 200)->withHeaders([
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function segment(Episode $episode, Request $request)
    {
        $url = (string) $request->query('u', '');
        $sig = (string) $request->query('s', '');
        $exp = (int) $request->query('e', 0);
        abort_unless($url !== '' && $exp >= time() && hash_equals($this->segSig($episode, $url, $exp), $sig), 403);
        abort_unless(str_starts_with($url, 'https://'), 400);

        $ref = (string) $request->query('r', '');

        // Retry a transient upstream hiccup a couple of times before giving up — one failed segment
        // shouldn't be enough to stall the whole stream (there's no lower rendition to fall back to).
        $resp = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $resp = Http::withHeaders($this->headers($ref ?: null))->connectTimeout(8)->timeout(30)->get($url);
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

        return response($data, 200)->withHeaders([
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'public, max-age=86400',
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

    private function proxyUrl(Episode $episode, string $abs, ?string $referer): string
    {
        $exp = time() + self::TTL;

        return route('stream.segment', $episode).'?'.http_build_query([
            'u' => $abs,
            'e' => $exp,
            's' => $this->segSig($episode, $abs, $exp),
            'r' => $referer ?: '',
        ]);
    }

    /**
     * Proxy URL for a child playlist of a master (a bitrate variant or an alternate audio rendition):
     * back through this same route with the sub-playlist as a signed `u`, so it gets its own segment
     * rewriting. Carries the caller's manifest token — this route refuses to serve without one.
     */
    private function nestedManifestUrl(Episode $episode, string $abs, ?string $referer, string $token): string
    {
        $exp = time() + self::TTL;

        return route('stream.manifest', $episode).'?'.http_build_query([
            't' => $token,
            'u' => $abs,
            'e' => $exp,
            's' => $this->segSig($episode, $abs, $exp),
            'r' => $referer ?: '',
            'd' => 1,   // depth marker — a nested playlist never nests again
        ]);
    }

    /**
     * Validate the signed `u` on a nested-playlist request, or null when this is a plain top-level
     * manifest request. Same HMAC as a proxied segment, so a caller can only ask for a sub-playlist we
     * ourselves emitted — never an arbitrary URL (SSRF).
     */
    private function signedNestedUrl(Episode $episode, Request $request): ?string
    {
        $url = (string) $request->query('u', '');
        if ($url === '') {
            return null;
        }
        $exp = (int) $request->query('e', 0);
        abort_unless(
            $exp >= time() && hash_equals($this->segSig($episode, $url, $exp), (string) $request->query('s', '')),
            403,
        );
        abort_unless(str_starts_with($url, 'https://'), 400);

        return $url;
    }

    /** HMAC over "app.key" — truncated so signed URLs stay short. */
    private static function sig(string $data): string
    {
        return substr(hash_hmac('sha256', $data, (string) config('app.key')), 0, 40);
    }

    /** Expiring signature for one proxied segment (bound to the episode + upstream url + expiry). */
    private function segSig(Episode $episode, string $url, int $exp): string
    {
        return self::sig('seg|'.$episode->id.'|'.$url.'|'.$exp);
    }

    /**
     * Short-lived manifest token, minted by EpisodeSourceController (the single, authenticated
     * resolver) and required by manifest(). Public so the resolver can call it.
     */
    public static function token(Episode $episode): string
    {
        $exp = time() + self::TTL;

        return $exp.'.'.self::sig('m|'.$episode->id.'|'.$exp);
    }

    private function tokenOk(Episode $episode, string $tok): bool
    {
        [$exp, $s] = array_pad(explode('.', $tok, 2), 2, '');

        return ctype_digit((string) $exp) && (int) $exp >= time()
            && hash_equals(self::sig('m|'.$episode->id.'|'.$exp), (string) $s);
    }

    /** Browsers embedding our player on another site send a foreign Referer → block. The native app
     *  and hls.js/segment fetches send none (or ours) → allowed (the token already gates them). */
    private function blockForeignEmbed(Request $request): void
    {
        $ref = (string) $request->headers->get('referer', '');
        if ($ref !== '' && ! preg_match('~^https?://(www\.)?netwix\.online~i', $ref)) {
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
