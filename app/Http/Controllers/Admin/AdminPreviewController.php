<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\SourceTitle;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADMIN-ONLY QA playback. Lets an admin watch anything for verification, bypassing the public gates
 * (unpublished / 18+-Pro / VIP) that the normal player enforces — this whole controller is behind the
 * admin middleware, so "see everything" is the point.
 *
 * Two resolvers produce a playable stream, both feeding ONE generic signed-(url+referer) proxy:
 *   - episode(): an already-imported Episode (content management "ตรวจสอบทุกตอน") — honours a forced backup.
 *   - source():  a NOT-yet-imported source title (the import page "ดูก่อน") — resolves ep-1 from the source.
 *
 * The proxy (manifest/segment/mp4) only ever fetches a URL WE signed (HMAC over app.key), so it can't be
 * driven as an open proxy even by an admin's browser. Mirrors [App\Http\Controllers\StreamController]'s
 * HLS rewrite + PNG-header strip, but keyed by the signed stream URL instead of an episode id — so it
 * needs no episode and never touches the public streaming path.
 */
class AdminPreviewController extends Controller
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    private const TTL = 3600; // 1h — one QA sitting

    /** Resolve an imported episode for admin QA (no publish/Pro/VIP gates). */
    public function episode(Episode $episode, SourceRegistry $registry): JsonResponse
    {
        if ($episode->video_url) {
            return $this->play($episode->video_url, null, str_contains($episode->video_url, '.m3u8') ? RemoteStream::KIND_HLS : RemoteStream::KIND_MP4);
        }

        $stream = $this->resolveEpisode($episode, $registry);

        return $stream ? $this->play($stream->url, $stream->referer, $stream->kind) : response()->json(['ready' => false], 202);
    }

    /**
     * Resolve a NOT-yet-imported source title for the import-page preview. Uses the source's own
     * fetchEpisodes to get the first playable ref (wowdrama keys episodes by wp post-id, not "1"), then
     * resolves that — so the same code previews a Halim movie, a wowdrama series and a 9nung title alike.
     */
    public function source(SourceTitle $sourceTitle, SourceRegistry $registry): JsonResponse
    {
        $source = $registry->get($sourceTitle->source);
        abort_unless($source, 404);

        $rs = new RemoteSeries(
            source: $sourceTitle->source,
            sourceKey: $sourceTitle->source_key,
            title: $sourceTitle->title,
            cleanTitle: $sourceTitle->displayTitle(),
            extra: $sourceTitle->extra ?? [],
        );

        try {
            $eps = $source->fetchEpisodes($rs);
            $ref = (string) ($eps[0]['ref'] ?? '1');
            $stream = $source->resolveByRef($sourceTitle->source_key, $ref);
        } catch (\Throwable $e) {
            $stream = null;
        }

        return $stream ? $this->play($stream->url, $stream->referer, $stream->kind) : response()->json(['ready' => false], 202);
    }

    /** Build a {ready,kind,url} where url is a signed admin proxy for this upstream (url + referer). */
    private function play(string $url, ?string $referer, string $kind): JsonResponse
    {
        // Embed sources (9nung/abyss) aren't proxyable — hand the embed page straight to the iframe.
        if ($kind === RemoteStream::KIND_EMBED) {
            return response()->json(['ready' => true, 'kind' => 'embed', 'url' => $url]);
        }

        $exp = time() + self::TTL;
        $route = $kind === RemoteStream::KIND_HLS ? 'admin.preview.manifest' : 'admin.preview.mp4';
        $proxied = route($route).'?'.http_build_query([
            'u' => $url,
            'r' => $referer ?: '',
            'e' => $exp,
            's' => $this->specSig($url, $referer, $exp),
        ]);

        return response()->json(['ready' => true, 'kind' => $kind, 'url' => $proxied]);
    }

    /** Proxied HLS manifest: fetch the signed upstream playlist, re-point its segments through us. */
    public function manifest(Request $request)
    {
        [$url, $ref, $exp] = $this->verified($request);

        try {
            $body = Http::withHeaders($this->headers($ref))->connectTimeout(8)->timeout(30)->get($url)->body();
        } catch (\Throwable $e) {
            abort(504, 'upstream manifest unavailable');
        }
        $base = $this->baseUrl($url);

        $out = collect(preg_split('/\r?\n/', $body))->map(function (string $line) use ($base, $ref) {
            $trim = trim($line);
            if ($trim === '') {
                return $line;
            }
            if (str_starts_with($trim, '#')) {
                return preg_replace_callback('/URI="([^"]+)"/', fn ($m) => 'URI="'.$this->segUrl($this->absolute($m[1], $base), $ref).'"', $line);
            }
            $abs = $this->absolute($trim, $base);
            // Nested playlist → re-proxy through manifest (a fresh signed spec); else a segment.
            return str_ends_with(strtolower(parse_url($abs, PHP_URL_PATH) ?? ''), '.m3u8')
                ? $this->manifestUrl($abs, $ref)
                : $this->segUrl($abs, $ref);
        })->implode("\n");

        return response($out, 200)->withHeaders([
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Proxied HLS segment: fetch upstream with the source Referer, strip the fake PNG header. */
    public function segment(Request $request)
    {
        $url = (string) $request->query('u', '');
        $exp = (int) $request->query('e', 0);
        abort_unless($url !== '' && $exp >= time() && hash_equals($this->segSig($url, $exp), (string) $request->query('s', '')), 403);
        abort_unless(str_starts_with($url, 'https://'), 400);
        $ref = (string) $request->query('r', '');

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
            usleep(250000);
        }
        abort_unless($resp && $resp->ok(), 502);

        $data = $resp->body();
        $pos = strpos($data, "\x47");
        if ($pos !== false && $pos > 0 && $pos < 512) {
            $data = substr($data, $pos);
        }

        return response($data, 200)->withHeaders(['Content-Type' => 'video/mp2t', 'Cache-Control' => 'no-store']);
    }

    /** Proxied progressive MP4 (rongyok / signed CDN) with Range support. */
    public function mp4(Request $request): StreamedResponse
    {
        [$url, $ref] = $this->verified($request);

        try {
            $upstream = Http::withHeaders(array_filter([
                'User-Agent' => self::UA,
                'Referer' => $ref,
                'Range' => $request->header('Range'),
            ]))->withOptions(['stream' => true])->connectTimeout(8)->timeout(60)->get($url);
        } catch (\Throwable $e) {
            abort(504, 'upstream video unavailable');
        }

        $headers = array_filter([
            'Content-Type' => $upstream->header('Content-Type') ?: 'video/mp4',
            'Content-Length' => $upstream->header('Content-Length') ?: null,
            'Content-Range' => $upstream->header('Content-Range') ?: null,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store',
        ]);

        return response()->stream(function () use ($upstream) {
            $body = $upstream->toPsrResponse()->getBody();
            while (! $body->eof()) {
                echo $body->read(1024 * 256);
                flush();
            }
        }, $upstream->status(), $headers);
    }

    // ------------------------------------------------------------- helpers

    /** Effective (backup-aware) stream for an episode — mirrors StreamController::resolve, no caching. */
    private function resolveEpisode(Episode $episode, SourceRegistry $registry): ?RemoteStream
    {
        if (! $episode->source || ! $episode->source_ref) {
            return null;
        }
        $hasBackup = $episode->backup_source && $episode->backup_key;
        $primary = fn () => $this->via($registry, $episode->source, $episode->content->source_key ?? '', (string) $episode->source_ref);
        $backup = fn () => $this->via($registry, (string) $episode->backup_source, (string) $episode->backup_key, (string) ($episode->backup_ref ?: $episode->source_ref));

        $s = ($episode->backup_forced && $hasBackup) ? $backup() : $primary();
        if ($s === null) {
            $s = ($episode->backup_forced && $hasBackup) ? $primary() : ($hasBackup ? $backup() : null);
        }

        return $s;
    }

    private function via(SourceRegistry $registry, string $sourceId, string $key, string $ref): ?RemoteStream
    {
        $source = $registry->get($sourceId);
        if (! $source || $key === '' || $ref === '') {
            return null;
        }
        try {
            return $source->resolveByRef($key, $ref);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Verify a signed (u, r, e) spec on a manifest/mp4 request → [url, referer, exp]. */
    private function verified(Request $request): array
    {
        $url = (string) $request->query('u', '');
        $ref = (string) $request->query('r', '');
        $exp = (int) $request->query('e', 0);
        abort_unless($url !== '' && $exp >= time() && hash_equals($this->specSig($url, $ref ?: null, $exp), (string) $request->query('s', '')), 403);
        abort_unless(str_starts_with($url, 'https://'), 400);

        return [$url, $ref, $exp];
    }

    private function manifestUrl(string $url, string $ref): string
    {
        $exp = time() + self::TTL;

        return route('admin.preview.manifest').'?'.http_build_query(['u' => $url, 'r' => $ref, 'e' => $exp, 's' => $this->specSig($url, $ref ?: null, $exp)]);
    }

    private function segUrl(string $url, string $ref): string
    {
        $exp = time() + self::TTL;

        return route('admin.preview.segment').'?'.http_build_query(['u' => $url, 'r' => $ref, 'e' => $exp, 's' => $this->segSig($url, $exp)]);
    }

    private function specSig(string $url, ?string $ref, int $exp): string
    {
        return $this->sig('apv|'.$url.'|'.($ref ?? '').'|'.$exp);
    }

    private function segSig(string $url, int $exp): string
    {
        return $this->sig('apvseg|'.$url.'|'.$exp);
    }

    private function sig(string $data): string
    {
        return substr(hash_hmac('sha256', $data, (string) config('app.key')), 0, 40);
    }

    private function headers(?string $referer): array
    {
        return array_filter(['User-Agent' => self::UA, 'Accept' => '*/*', 'Referer' => $referer]);
    }

    private function baseUrl(string $url): string
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '').rtrim(dirname($p['path'] ?? '/'), '/').'/';
    }

    private function absolute(string $uri, string $base): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        $p = parse_url($base);
        if (str_starts_with($uri, '//')) {
            return ($p['scheme'] ?? 'https').':'.$uri;
        }
        if (str_starts_with($uri, '/')) {
            return ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').$uri;
        }

        return $base.$uri;
    }
}
