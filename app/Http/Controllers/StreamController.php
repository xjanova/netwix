<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
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

    public function manifest(Episode $episode, SourceRegistry $registry)
    {
        $this->gateAdult($episode);
        $stream = $this->resolve($episode, $registry);
        if (! $stream || $stream->kind !== RemoteStream::KIND_HLS) {
            abort(404);
        }

        // Rewriting the upstream playlist means fetching a big (100k+) manifest and signing every one
        // of its ~700 segment URLs — slow (several seconds) on a cold hit. Cache the finished playlist
        // briefly so re-plays / seeks start instantly; segment signatures stay valid far longer.
        $out = Cache::remember("ep_manifest:{$episode->id}", now()->addMinutes(10), function () use ($episode, $stream) {
            // A dead/slow upstream (e.g. a rotated tiktokcdn link) must not bubble up as an uncaught
            // ConnectionException — that spammed the ERROR log. Fail fast on connect, return a clean 504.
            try {
                $body = Http::withHeaders($this->headers($stream->referer))->connectTimeout(8)->timeout(30)->get($stream->url)->body();
            } catch (\Throwable $e) {
                abort(504, 'upstream manifest unavailable');
            }
            $base = $this->baseUrl($stream->url);

            return collect(preg_split('/\r?\n/', $body))->map(function (string $line) use ($base, $episode, $stream) {
                $trim = trim($line);
                if ($trim === '') {
                    return $line;
                }
                // #EXT-X-KEY / media URIs inside tags
                if (str_starts_with($trim, '#')) {
                    return preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($base, $episode, $stream) {
                        return 'URI="'.$this->proxyUrl($episode, $this->absolute($m[1], $base), $stream->referer).'"';
                    }, $line);
                }
                // a segment or sub-playlist URI
                $abs = $this->absolute($trim, $base);

                return str_ends_with(strtolower(parse_url($abs, PHP_URL_PATH) ?? ''), '.m3u8')
                    ? route('stream.manifest', $episode) // nested playlist → re-proxy through manifest
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
        abort_unless($url !== '' && hash_equals($this->sign($episode, $url), $sig), 403);
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

        $data = $resp->body();
        // Strip the fake image header: seek to the first MPEG-TS sync byte (0x47).
        $pos = strpos($data, "\x47");
        if ($pos !== false && $pos > 0 && $pos < 512) {
            $data = substr($data, $pos);
        }

        return response($data, 200)->withHeaders([
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function mp4(Episode $episode, Request $request, SourceRegistry $registry): StreamedResponse
    {
        $this->gateAdult($episode);
        $stream = $this->resolve($episode, $registry);
        abort_if(! $stream, 404);

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

    private function resolve(Episode $episode, SourceRegistry $registry): ?RemoteStream
    {
        if (! $episode->source || ! $episode->source_ref) {
            return $episode->video_url
                ? new RemoteStream(str_contains($episode->video_url, '.m3u8') ? RemoteStream::KIND_HLS : RemoteStream::KIND_MP4, $episode->video_url)
                : null;
        }
        $source = $registry->get($episode->source);
        if (! $source) {
            return null;
        }
        $key = $episode->content->source_key ?? '';

        $cached = Cache::remember("ep_raw:{$episode->id}", now()->addMinutes(10), function () use ($source, $key, $episode) {
            $s = $source->resolveByRef($key, $episode->source_ref);

            return $s ? ['kind' => $s->kind, 'url' => $s->url, 'referer' => $s->referer] : null;
        });
        if (! $cached) {
            Cache::forget("ep_raw:{$episode->id}");

            return null;
        }

        return new RemoteStream($cached['kind'], $cached['url'], $cached['referer'] ?? null);
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
        return route('stream.segment', $episode).'?'.http_build_query([
            'u' => $abs,
            's' => $this->sign($episode, $abs),
            'r' => $referer ?: '',
        ]);
    }

    private function sign(Episode $episode, string $url): string
    {
        return hash_hmac('sha256', $episode->id.'|'.$url, (string) config('app.key'));
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
        if (str_starts_with($uri, '/')) {
            $p = parse_url($base);

            return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').$uri;
        }

        return $base.$uri;
    }
}
