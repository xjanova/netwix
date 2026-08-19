<?php

namespace App\Support;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Measures how many bytes ONE episode of a source really is, without downloading it.
 *
 * This exists so the storage plan is arithmetic on measurements instead of a guess. Every "if we
 * mirror everything it will be N TB and cost $M" on the monitor traces back to rows this class wrote.
 *
 * Two shapes, both cheap:
 *   - **MP4** — a ranged GET of one byte; the server answers `Content-Range: bytes 0-0/12345678` and
 *     the last number is the file size. (A plain HEAD is refused by several of these CDNs.)
 *   - **HLS** — read the manifest, follow a master to its first variant, then measure a handful of
 *     segments and scale by the segment count. Segments in one episode are near-identical in length,
 *     so a 4-of-900 sample lands within a few percent — and the sample size is recorded either way.
 *
 * It never downloads a whole file, so it is safe to run from a web request. It is also polite: at most
 * a few requests per episode, which is the same footprint as a viewer opening the player.
 */
class MirrorProbe
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** How many segments of an HLS episode to weigh before extrapolating. */
    private const HLS_SAMPLE = 4;

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Probe up to $count random episodes of one source and store what we learn.
     *
     * @return array{ok:int,fail:int,avg:?int,errors:array<int,string>}
     */
    public function run(string $source, int $count = 3): array
    {
        $episodes = Episode::query()
            ->whereHas('content', fn ($q) => $q->where('source', $source)->whereNotNull('source_key'))
            ->whereNotNull('source_ref')
            ->where('source_ref', '<>', '')
            ->with('content')
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($episodes->isEmpty()) {
            return ['ok' => 0, 'fail' => 0, 'avg' => null, 'errors' => ['ไม่มีตอนที่มีลิงก์ต้นทางให้วัด']];
        }

        $ok = 0;
        $fail = 0;
        $sum = 0;
        $errors = [];

        foreach ($episodes as $episode) {
            $r = $this->one($episode, $source);
            DB::table('mirror_probes')->insert([
                'source' => $source,
                'episode_id' => $episode->id,
                'kind' => $r['kind'],
                'bytes' => $r['bytes'],
                'seconds' => $r['seconds'],
                'ok' => $r['bytes'] !== null,
                'error' => $r['error'] ? mb_substr($r['error'], 0, 250) : null,
                'measured_at' => now(),
            ]);

            if ($r['bytes'] !== null) {
                $ok++;
                $sum += $r['bytes'];
            } else {
                $fail++;
                $errors[] = $r['error'] ?? 'ไม่ทราบสาเหตุ';
            }
        }

        return [
            'ok' => $ok,
            'fail' => $fail,
            'avg' => $ok ? (int) round($sum / $ok) : null,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @return array{bytes:?int,seconds:?int,kind:?string,error:?string} */
    private function one(Episode $episode, string $source): array
    {
        $blank = ['bytes' => null, 'seconds' => null, 'kind' => null, 'error' => null];

        $content = $episode->content;
        $handler = $this->registry->get($source);
        if (! $handler || ! $content?->source_key) {
            return array_merge($blank, ['error' => 'ไม่รู้จักแหล่งนี้']);
        }

        try {
            $stream = $handler->resolveByRef((string) $content->source_key, (string) $episode->source_ref);
        } catch (Throwable $e) {
            return array_merge($blank, ['error' => 'หาลิงก์ไม่ได้: '.mb_substr($e->getMessage(), 0, 120)]);
        }

        if (! $stream || $stream->url === '') {
            return array_merge($blank, ['error' => 'ต้นทางไม่คืนลิงก์ (อาจตายหรือหมุนลิงก์)']);
        }
        if ($stream->kind === RemoteStream::KIND_EMBED) {
            return array_merge($blank, ['kind' => 'embed', 'error' => 'ต้นทางให้มาเป็นหน้าเพลเยอร์ ไม่ใช่ไฟล์ — วัดขนาดไม่ได้']);
        }

        return $stream->kind === RemoteStream::KIND_MP4
            ? $this->measureMp4($stream)
            : $this->measureHls($stream);
    }

    /** @return array{bytes:?int,seconds:?int,kind:string,error:?string} */
    private function measureMp4(RemoteStream $stream): array
    {
        try {
            $resp = Http::withHeaders($this->headers($stream) + ['Range' => 'bytes=0-0'])
                ->timeout(25)->get($stream->url);
        } catch (Throwable $e) {
            return ['bytes' => null, 'seconds' => null, 'kind' => 'mp4', 'error' => 'ต่อไม่ติด: '.mb_substr($e->getMessage(), 0, 100)];
        }

        if (! $resp->successful()) {
            return ['bytes' => null, 'seconds' => null, 'kind' => 'mp4', 'error' => 'ต้นทางตอบ HTTP '.$resp->status()];
        }

        // 206 + Content-Range is the reliable answer; a 200 means the server ignored the range, in
        // which case Content-Length is the whole file and equally usable.
        $range = $resp->header('Content-Range');
        if ($range && preg_match('~/(\d+)$~', $range, $m)) {
            return ['bytes' => (int) $m[1], 'seconds' => null, 'kind' => 'mp4', 'error' => null];
        }
        $len = (int) $resp->header('Content-Length');
        if ($resp->status() === 200 && $len > 100_000) {
            return ['bytes' => $len, 'seconds' => null, 'kind' => 'mp4', 'error' => null];
        }

        return ['bytes' => null, 'seconds' => null, 'kind' => 'mp4', 'error' => 'ต้นทางไม่บอกขนาดไฟล์ (HTTP '.$resp->status().')'];
    }

    /**
     * Read the manifest, sample a few segments, scale up.
     *
     * @return array{bytes:?int,seconds:?int,kind:string,error:?string}
     */
    private function measureHls(RemoteStream $stream): array
    {
        $fail = fn (string $why) => ['bytes' => null, 'seconds' => null, 'kind' => 'hls', 'error' => $why];

        $url = $stream->url;
        $body = $this->fetch($url, $stream);
        if ($body === null) {
            return $fail('อ่านไฟล์ playlist ไม่ได้');
        }
        $body = HlsManifest::unwrap($body);

        // A master playlist lists other playlists; follow the first variant to reach real segments.
        if (! str_contains($body, '#EXTINF') && str_contains($body, '#EXT-X-STREAM-INF')) {
            $variant = null;
            foreach (preg_split('~\r?\n~', $body) as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $variant = $this->absolute($line, $url);
                    break;
                }
            }
            if ($variant === null) {
                return $fail('playlist หลักไม่มีรายการย่อย');
            }
            $url = $variant;
            $body = $this->fetch($url, $stream);
            if ($body === null) {
                return $fail('อ่าน playlist ย่อยไม่ได้');
            }
            $body = HlsManifest::unwrap($body);
        }

        $segments = [];
        $seconds = 0.0;
        foreach (preg_split('~\r?\n~', $body) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXTINF')) {
                $seconds += (float) preg_replace('~[^0-9.].*$~', '', substr($line, 8));
            } elseif ($line !== '' && ! str_starts_with($line, '#')) {
                $segments[] = $this->absolute($line, $url);
            }
        }
        if ($segments === []) {
            return $fail('playlist ไม่มีไฟล์ย่อย');
        }

        $sampled = 0;
        $sampledBytes = 0;
        foreach (array_slice($segments, 0, self::HLS_SAMPLE) as $seg) {
            $size = $this->sizeOf($seg, $stream);
            if ($size !== null) {
                $sampled++;
                $sampledBytes += $size;
            }
        }
        if ($sampled === 0) {
            return $fail('วัดขนาดไฟล์ย่อยไม่ได้');
        }

        $avgSeg = $sampledBytes / $sampled;
        $total = (int) round($avgSeg * count($segments));

        // A whole episode under a megabyte is a measurement artefact, not a small file. Refusing it
        // keeps a nonsense average out of the storage projection, which is the one thing this class
        // exists to protect.
        if ($total < 1_000_000) {
            return $fail('ขนาดที่วัดได้เล็กผิดปกติ — ต้นทางไม่ยอมบอกขนาดไฟล์ย่อย');
        }

        return [
            'bytes' => $total,
            'seconds' => $seconds > 0 ? (int) round($seconds) : null,
            'kind' => 'hls',
            'error' => null,
        ];
    }

    private function sizeOf(string $url, RemoteStream $stream): ?int
    {
        try {
            $resp = Http::withHeaders($this->headers($stream) + ['Range' => 'bytes=0-0'])
                ->timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        // A 404 page carries a Content-Range too. hd432's segments were being fetched at a wrong URL,
        // and the error page's "bytes 0-0/1170" was read as the segment size — which is how a feature
        // film came out at 1.4 MB. Nothing about a failed response is a measurement.
        if (! $resp->successful()) {
            return null;
        }

        $range = $resp->header('Content-Range');
        if ($range && preg_match('~/(\d+)$~', $range, $m)) {
            return (int) $m[1];
        }

        // Content-Length is only the FILE size when the server ignored our range and sent the whole
        // thing (200). On a 206 it is the length of the one byte we asked for — trusting it there is
        // what made hd432 report 2.8 MB for a feature film.
        $len = (int) $resp->header('Content-Length');

        return $resp->status() === 200 && $len > 0 ? $len : null;
    }

    private function fetch(string $url, RemoteStream $stream): ?string
    {
        try {
            $resp = Http::withHeaders($this->headers($stream))->timeout(25)->get($url);
        } catch (Throwable) {
            return null;
        }

        return $resp->successful() ? $resp->body() : null;
    }

    private function headers(RemoteStream $stream): array
    {
        $h = ['User-Agent' => self::UA];
        if ($stream->referer) {
            $h['Referer'] = $stream->referer;
            $h['Origin'] = rtrim((string) preg_replace('~^(https?://[^/]+).*$~', '$1', $stream->referer), '/');
        }

        return $h;
    }

    /** Resolve a manifest line against the playlist it came from. */
    private function absolute(string $line, string $base): string
    {
        if (str_starts_with($line, 'http://') || str_starts_with($line, 'https://')) {
            return $line;
        }
        $parts = parse_url($base);

        // Protocol-relative ("//host/path") points at a DIFFERENT host and must inherit only the
        // scheme. Treating it as a path — as this did — silently rewrote hd432's segments onto the
        // manifest's host and every fetch 404'd.
        if (str_starts_with($line, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$line;
        }

        $root = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($line, '/')) {
            return $root.$line;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $root.$dir.'/'.$line;
    }
}
