<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Stamps the NetWix logo into the middle of a cover image, faintly.
 *
 * The point is attribution that survives being taken. A cover is the one asset that gets copied by
 * hand — right-click, save, re-upload — and none of the usual defences reach that: our posters are
 * served from Cloudflare's edge (`cf-cache-status: HIT`), so a Referer check in PHP never runs, and
 * the origin answers on its bare IP, so an edge rule can be walked around too (both measured
 * 2026-08-19). A mark inside the pixels needs no request to inspect; it travels with the file.
 *
 * Centre, large and very faint, rather than a small corner tag. A corner is trivially cropped off; a
 * mark across the middle cannot be removed without destroying the artwork it sits on. The trade is
 * that it has to be quiet enough not to spoil the poster, which is the whole reason for the
 * auto-contrast below.
 *
 * rongyok and goseries4k both do this to us — goseries4k with an opaque "GOSERIES 4K" bar straight
 * across the middle. Their execution is what NOT to copy: it wrecks the artwork on their own site
 * too. Ours stays under a fifth of full strength.
 */
class PosterWatermark
{
    /** Logo width as a share of the cover's width. */
    private const WIDTH_RATIO = 0.70;

    /** Baseline strength, in percent of full opacity. Owner-tuned: 20 reads without covering faces. */
    private const BASE_OPACITY = 20;

    /**
     * How far auto-contrast may move that baseline, up or down.
     *
     * A single fixed opacity does not work across a real catalogue: at 20% the logo reads clearly on
     * a pale poster and nearly vanishes on a saturated red or a dark one, because our logo is itself
     * red-violet and mid-toned. The adjustment keeps the PERCEIVED strength roughly constant instead
     * of the numeric one.
     */
    private const OPACITY_SWING = 9;

    /** Below this width the image is a thumbnail, not a cover — marking it just damages it. */
    private const MIN_IMAGE_WIDTH = 200;

    /** Admin switch — off means every call is a no-op, so it can be turned off without a deploy. */
    public static function enabled(): bool
    {
        return Setting::flag('poster_watermark_enabled', false);
    }

    /** Absolute path of the logo PNG (transparent) that gets stamped. */
    public static function logoPath(): ?string
    {
        $configured = trim((string) Setting::get('poster_watermark_logo', ''));
        $candidates = array_filter([
            $configured !== '' ? public_path(ltrim($configured, '/')) : null,
            public_path('assets/netwix-logo2.png'),
            public_path('assets/netwix-icon.png'),
        ]);

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Draw the mark onto a GD image, in place. Returns false when it was skipped — no logo file, or
     * the image is too small — so a caller can tell "not marked" from "marked", and a missing asset
     * can never cost us the cover itself.
     */
    public static function apply(\GdImage $img): bool
    {
        $logoPath = self::logoPath();
        if ($logoPath === null) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < self::MIN_IMAGE_WIDTH) {
            return false;
        }

        $logo = @imagecreatefrompng($logoPath);
        if ($logo === false) {
            return false;
        }

        $lw = (int) max(1, round($w * self::WIDTH_RATIO));
        $lh = (int) max(1, round(imagesy($logo) * $lw / imagesx($logo)));
        $x = (int) round(($w - $lw) / 2);
        $y = (int) round(($h - $lh) / 2);

        $opacity = self::opacityFor($img, $x, $y, $lw, $lh);

        // Resize with the alpha channel preserved — the default blending mode would flatten the
        // logo's transparency onto black and stamp a rectangle.
        $scaled = imagecreatetruecolor($lw, $lh);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        imagedestroy($logo);

        // IMG_FILTER_COLORIZE's 4th argument ADDS to each pixel's alpha, which is how a whole
        // transparent PNG gets faded uniformly in GD.
        $fade = (int) round(127 * (1 - $opacity / 100));
        if ($fade > 0) {
            imagefilter($scaled, IMG_FILTER_COLORIZE, 0, 0, 0, $fade);
        }

        imagealphablending($img, true);
        imagecopy($img, $scaled, $x, $y, 0, 0, $lw, $lh);
        imagedestroy($scaled);

        return true;
    }

    /**
     * Pick an opacity for THIS cover by looking at what sits behind the logo.
     *
     * Measures the mean luminance of the region the logo will cover. Mid-toned artwork is where a
     * mid-toned logo disappears, so that gets the strongest setting; artwork that is already very
     * dark or very bright contrasts with the logo on its own and gets eased back, which also keeps
     * the mark from shouting on a pale poster.
     */
    private static function opacityFor(\GdImage $img, int $x, int $y, int $lw, int $lh): int
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $sum = 0;
        $n = 0;
        $step = max(4, (int) round(min($lw, $lh) / 24));   // sparse sample — this runs per image

        for ($sy = max(0, $y); $sy < min($h, $y + $lh); $sy += $step) {
            for ($sx = max(0, $x); $sx < min($w, $x + $lw); $sx += $step) {
                $rgb = imagecolorat($img, $sx, $sy);
                // Rec. 601 luma — closer to perceived brightness than a flat RGB average.
                $sum += 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $n++;
            }
        }
        if ($n === 0) {
            return self::BASE_OPACITY;
        }

        $luma = $sum / $n;                              // 0..255
        $distanceFromMid = abs($luma - 128) / 128;      // 0 at mid-grey, 1 at pure black/white
        $opacity = self::BASE_OPACITY + (int) round(self::OPACITY_SWING * (1 - $distanceFromMid));

        return max(8, min(40, $opacity));
    }
}
