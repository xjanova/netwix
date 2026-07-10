<?php

namespace App\Support;

/**
 * Shared helper for the HLS segment proxies ([App\Http\Controllers\StreamController] and
 * [App\Http\Controllers\Admin\AdminPreviewController]).
 *
 * The backup-pool CDNs disguise their MPEG-TS segments as images so a free host will serve them
 * (getplay-cdn, torbo007's tiktokcdn): the bytes are a small real image header (+ some padding),
 * then the actual transport stream. To play them we strip everything before the TS payload.
 */
class HlsSegment
{
    /**
     * Return $data from the start of its MPEG-TS payload, stripping any leading fake-image wrapper.
     *
     * TS packets are 188 bytes and every packet starts with the 0x47 sync byte, so the real stream
     * begins at the first 0x47 that starts an actual 188-byte-aligned RUN of sync bytes. A plain
     * "first 0x47" scan is NOT enough: a PNG-wrapped segment (goseries4k/torbo007) starts with the
     * PNG signature `89 50 4E 47 …`, whose 4th byte IS 0x47 — stripping there would hand the player
     * the rest of the PNG and it would fail to decode. Anchoring on the aligned run skips that stray
     * byte and lands on the true TS start (offset 252 for torbo007's ~70-byte PNG + padding).
     */
    public static function stripToTsSync(string $data): string
    {
        // Already clean TS (anime108 / most Halim CDNs serve the raw stream) — nothing to strip.
        if (($data[0] ?? '') === "\x47") {
            return $data;
        }

        // Wrapped: find the first 0x47 that begins a 188-aligned run of ≥4 sync bytes. The wrapper is
        // tiny in practice (<512B); cap the scan so a corrupt/imageless body can't burn CPU per-segment.
        $limit = min(strlen($data) - 3 * 188, 4096);
        for ($i = 0; $i < $limit; $i++) {
            if ($data[$i] === "\x47"
                && $data[$i + 188] === "\x47"
                && $data[$i + 376] === "\x47"
                && $data[$i + 564] === "\x47") {
                return $i > 0 ? substr($data, $i) : $data;
            }
        }

        // Fallback (segment shorter than the run check needs, or an unexpected wrapper): the original
        // first-0x47 heuristic that kept the earlier single-image-header sources working.
        $pos = strpos($data, "\x47");

        return ($pos !== false && $pos > 0 && $pos < 512) ? substr($data, $pos) : $data;
    }
}
