<?php

namespace App\Support;

use App\Services\Import\RemoteStream;
use Illuminate\Support\Facades\Http;

/**
 * Answers the only question that matters about a resolved stream: would a viewer see video?
 *
 * Resolving is not playing. On 2026-09-19 every wow-drama title was unplayable for weeks while
 * `resolveByRef()` returned a perfectly good `hls` URL for all of them — the break was one level
 * further in, in the child playlist of a master. The canary asked "did it resolve?", the answer was
 * yes, and nothing ever alerted. So this asks the whole question instead: fetch the playlist, and if
 * it is a MASTER, follow one of its children, because that is the level a real player reaches and
 * the level that failed.
 *
 * Used by [App\Console\Commands\SourceCanaryCommand]. Kept deliberately cheap — one or two small
 * GETs, never a segment — so probing stays polite to sources we do not own.
 */
class PlaybackProbe
{
    /** Same UA as [App\Http\Controllers\StreamController] — getplay-cdn binds its token to it. */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    private const TIMEOUT = 20;

    /**
     * True when $stream really yields playable media.
     *
     * Throws whatever the HTTP client throws — the caller (the canary) already separates "we could
     * not reach them" from "they answered and refused", and that distinction must not be flattened
     * here. A definitive rejection is a verdict; a timeout is not.
     */
    public static function plays(RemoteStream $stream): bool
    {
        if ($stream->kind === RemoteStream::KIND_EMBED) {
            return true;   // a third-party player page; nothing of ours to verify
        }

        if ($stream->kind === RemoteStream::KIND_MP4) {
            // One byte is enough to prove the file is really being served.
            $head = self::http($stream->referer)->withHeaders(['Range' => 'bytes=0-0'])->get($stream->url);

            return $head->successful() && str_starts_with((string) $head->header('Content-Type'), 'video');
        }

        $body = HlsManifest::unwrap(self::http($stream->referer)->get($stream->url)->body());
        if (! str_contains($body, '#EXTM3U')) {
            return false;
        }

        // A MEDIA playlist already lists segments — it plays. A MASTER lists other playlists, and a
        // master whose children 403 is exactly the failure this class exists to catch, so follow one.
        if (! str_contains($body, '#EXT-X-STREAM-INF')) {
            return self::hasChildren($body);
        }

        $child = self::firstChild($body, $stream->url);
        if ($child === null) {
            return false;
        }

        $childBody = HlsManifest::unwrap(self::http($stream->referer)->get($child)->body());

        return str_contains($childBody, '#EXTM3U') && self::hasChildren($childBody);
    }

    private static function http(?string $referer)
    {
        return Http::withHeaders(array_filter([
            'User-Agent' => self::UA,
            'Accept' => '*/*',
            'Referer' => $referer,
        ]))->connectTimeout(8)->timeout(self::TIMEOUT);
    }

    /** A playlist is only useful if it points at something — segments, or variants. */
    private static function hasChildren(string $playlist): bool
    {
        foreach (preg_split('/\r?\n/', $playlist) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                return true;
            }
        }

        return false;
    }

    /** First variant URI of a master, resolved against the master's own URL. */
    private static function firstChild(string $master, string $base): ?string
    {
        foreach (preg_split('/\r?\n/', $master) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'http://') || str_starts_with($line, 'https://')) {
                return $line;
            }

            $root = preg_replace('~^(https?://[^/]+).*$~', '$1', $base);
            if (str_starts_with($line, '/')) {
                return $root.$line;
            }

            return preg_replace('~[^/]*$~', '', $base).$line;
        }

        return null;
    }
}
