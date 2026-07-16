<?php

namespace App\Support;

/**
 * Shared helper for the HLS manifest proxies ([App\Http\Controllers\StreamController] and
 * [App\Http\Controllers\Admin\AdminPreviewController]).
 *
 * Some players don't serve their playlist as a raw `.m3u8` — they wrap it. animeruka's animemami
 * player (maimeorder CDN) serves the manifest as a `.txt` whose body is JSON `{"p":"<base64>"}`,
 * where the base64 decodes to a normal HLS media playlist (segments disguised as `.webp`, but real
 * MPEG-TS — see [HlsSegment]). Both proxies fetch the manifest body and then require it to start with
 * `#EXTM3U`, so this normalises the wrapper away first.
 *
 * The detection is SHAPE-based (valid JSON, one string key `p`, whose base64 decodes to a real
 * playlist), not source-specific: it needs no plumbing through the signed proxy spec, works in the
 * public and admin proxies alike, and is self-validating — a genuine `.m3u8` body isn't valid JSON,
 * so a real playlist is never mis-detected.
 */
class HlsManifest
{
    /**
     * Return a raw HLS playlist from a fetched manifest body, unwrapping the animemami/maimeorder
     * JSON-base64 envelope when present. A body that's already a playlist (or anything we don't
     * recognise) is returned unchanged.
     */
    public static function unwrap(string $body): string
    {
        // Already a playlist (or empty) — nothing to do. Cheap early-out avoids a JSON parse per hit.
        if ($body === '' || str_starts_with(ltrim($body), '#EXTM3U') || $body[0] !== '{') {
            return $body;
        }

        $json = json_decode($body, true);
        if (! is_array($json) || ! isset($json['p']) || ! is_string($json['p'])) {
            return $body;
        }

        $decoded = base64_decode($json['p'], true);
        if ($decoded === false || ! str_starts_with(ltrim($decoded), '#EXTM3U')) {
            return $body;   // not the wrapper we expected → leave the caller's #EXTM3U guard to reject it
        }

        return $decoded;
    }
}
