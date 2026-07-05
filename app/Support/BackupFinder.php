<?php

namespace App\Support;

use App\Models\Content;
use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Services\Import\Sources\HalimSource;
use Illuminate\Support\Facades\Http;

/**
 * Finds a working BACKUP stream for an un-playable title. When a title is auto-suspended (its own
 * source's link died — see [PlaybackHealth]), this searches every OTHER Halim pool site
 * ([SourceRegistry::backupPool]) for the same title, resolves its stream, and VERIFIES it actually
 * plays (master playlist + first segment fetch) before returning it. A verified backup is what the
 * netwix:find-backups bot applies to the episode's backup_* columns.
 *
 * Matching reuses [Content::dedupeKey] (loose normalised-title equality) and prefers a same-year hit.
 * Verification is deliberately strict: a false negative just leaves the title suspended (safe), while
 * a false positive would republish a broken title — so we only return a stream that fetched a real
 * MPEG-TS segment.
 */
class BackupFinder
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function __construct(private SourceRegistry $registry) {}

    /**
     * @return array{source:string,key:string,ref:string,display:string}|null
     */
    public function find(Content $content): ?array
    {
        $want = Content::dedupeKey($content->title);
        if ($want === '') {
            return null;
        }

        foreach ($this->registry->backupPool() as $source) {
            // A site can't back up its OWN title — the failing link is on this very CDN.
            if ($source->id() === $content->source) {
                continue;
            }

            $match = $this->matchOn($source, $content, $want);
            if ($match === null) {
                continue;
            }

            // Movies resolve/verify on episode 1; the same remote post id resolves every episode of a
            // series via its `episode` param, so the bot reuses this key with each episode's number.
            $stream = $source->resolveByRef($match->sourceKey, '1');
            if ($stream !== null && $this->plays($stream)) {
                return [
                    'source' => $source->id(),
                    'key' => $match->sourceKey,
                    'ref' => '1',
                    'display' => $source->displayName(),
                ];
            }
        }

        return null;
    }

    /** Best title match on one pool site by normalised-title equality, preferring the same year. */
    private function matchOn(HalimSource $source, Content $content, string $want): ?RemoteSeries
    {
        $weak = null;
        foreach ($source->search($content->title, 10) as $rs) {
            $ck = Content::dedupeKey($rs->cleanTitle !== '' ? $rs->cleanTitle : $rs->title);
            if ($ck !== $want) {
                continue;
            }
            // Same year (or unknown on either side) → confident match.
            if (! $content->year || ! $rs->year || (int) $content->year === (int) $rs->year) {
                return $rs;
            }
            $weak ??= $rs;   // title matches but year differs → only use if nothing better turns up
        }

        return $weak;
    }

    /**
     * Verify a resolved HLS stream really plays: the media playlist must list segments and the first
     * segment must fetch and start with a real MPEG-TS sync byte (Halim segments carry a fake image
     * header, exactly what [StreamController::segment] strips). Any failure → not playable.
     */
    public function plays(RemoteStream $stream): bool
    {
        if ($stream->kind !== RemoteStream::KIND_HLS) {
            return false;
        }

        try {
            $body = Http::withHeaders($this->headers($stream->referer))
                ->connectTimeout(8)->timeout(20)->get($stream->url)->body();
        } catch (\Throwable) {
            return false;
        }
        if (! str_contains($body, '#EXTINF')) {
            return false;
        }

        $seg = null;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $t = trim($line);
            if ($t !== '' && ! str_starts_with($t, '#')) {
                $seg = $t;
                break;
            }
        }
        if ($seg === null) {
            return false;
        }

        try {
            $resp = Http::withHeaders($this->headers($stream->referer) + ['Range' => 'bytes=0-8191'])
                ->connectTimeout(8)->timeout(20)->get($this->absolute($seg, $stream->url));
        } catch (\Throwable) {
            return false;
        }
        if (! $resp->ok()) {
            return false;
        }

        $data = $resp->body();
        if (strlen($data) < 512) {
            return false;   // too small to be a real .ts segment
        }
        $pos = strpos($data, "\x47");   // MPEG-TS sync, at/near the start once the fake header is skipped

        return $pos !== false && $pos < 512;
    }

    private function headers(?string $referer): array
    {
        return array_filter([
            'User-Agent' => self::UA,
            'Accept' => '*/*',
            'Referer' => $referer,
        ]);
    }

    private function absolute(string $uri, string $base): string
    {
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');
        if (str_starts_with($uri, '/')) {
            return $origin.$uri;
        }

        return $origin.rtrim(dirname($p['path'] ?? '/'), '/').'/'.$uri;
    }
}
