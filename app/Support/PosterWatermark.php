<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Burns "netwix.online" into a cover image.
 *
 * The point is attribution that survives being taken. A cover is the one asset that gets copied by
 * hand — right-click, save, re-upload — and none of the usual defences reach that: our posters are
 * served from Cloudflare's edge (`cf-cache-status: HIT`), so a Referer check in PHP never runs, and
 * the origin answers on its bare IP, so an edge rule can be walked around too (both measured
 * 2026-08-19). A mark inside the pixels needs no request to inspect. It travels with the file.
 *
 * rongyok proved the idea works on us: their "รับชมได้ที่ Rongyok.com" line is why the owner asked
 * for this. Their execution is worth not copying, though — they also replaced whole covers with a
 * green advert, which makes their own site look broken to their own viewers. A cover is our shop
 * window, so this stays deliberately restrained: bottom-right, small, translucent, with a shadow so
 * it stays legible on both dark and light artwork, and never across the middle where the faces are.
 */
class PosterWatermark
{
    /** Text height as a share of the image's SHORT side — scales with the poster instead of a fixed px. */
    private const SIZE_RATIO = 0.043;

    /** Inset from the edges, also relative, so the margin looks the same on any size. */
    private const PAD_RATIO = 0.035;

    /** Minimum legible size. Below this the mark is noise, so it is skipped entirely. */
    private const MIN_PX = 9;

    /** Below this width the image is a thumbnail, not a cover — marking it just damages it. */
    private const MIN_IMAGE_WIDTH = 200;

    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    ];

    /** Admin switch — off means every call is a no-op, so it can be turned off without a deploy. */
    public static function enabled(): bool
    {
        return Setting::flag('poster_watermark_enabled', false);
    }

    public static function text(): string
    {
        $t = trim((string) Setting::get('poster_watermark_text', 'netwix.online'));

        return $t !== '' ? $t : 'netwix.online';
    }

    /**
     * Draw the mark onto a GD image, in place. Returns false when it was skipped — no font available,
     * image too small, or the text would not be legible — so a caller can tell "not marked" from
     * "marked", and a missing font can never cost us the cover itself.
     */
    public static function apply(\GdImage $img): bool
    {
        $font = self::font();
        if ($font === null) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < self::MIN_IMAGE_WIDTH) {
            return false;
        }

        $size = (int) round(min($w, $h) * self::SIZE_RATIO);
        if ($size < self::MIN_PX) {
            return false;
        }

        $text = self::text();
        $box = @imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return false;
        }
        $textW = abs($box[4] - $box[0]);
        $textH = abs($box[5] - $box[1]);

        // A mark wider than half the cover stops being a mark and starts being a defacement.
        if ($textW > $w * 0.5) {
            return false;
        }

        $pad = (int) round(min($w, $h) * self::PAD_RATIO);
        $x = $w - $textW - $pad;
        $y = $h - $pad;

        imagealphablending($img, true);

        // Shadow first, then the text: artwork runs from near-black to near-white, and a single flat
        // colour disappears against one end or the other. The offset pair reads on both.
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 70);
        $ink = imagecolorallocatealpha($img, 255, 255, 255, 38);
        if ($shadow === false || $ink === false) {
            return false;
        }
        $off = max(1, (int) round($size * 0.08));
        @imagettftext($img, $size, 0, $x + $off, $y + $off, $shadow, $font, $text);
        @imagettftext($img, $size, 0, $x, $y, $ink, $font, $text);

        return true;
    }

    /** First usable TrueType font on this machine, or null (callers then skip the mark). */
    private static function font(): ?string
    {
        $configured = trim((string) config('services.watermark.font', ''));
        if ($configured !== '' && is_readable($configured)) {
            return $configured;
        }
        foreach (self::FONT_CANDIDATES as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}
