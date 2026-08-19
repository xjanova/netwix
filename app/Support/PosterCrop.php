<?php

namespace App\Support;

/**
 * Trims a strip off the bottom of a cover and restores the original proportions.
 *
 * WHY THIS RATHER THAN DETECTING EACH SITE'S MARK. Every Thai source brands its posters, and the
 * marks have nothing in common: goseries4k uses orange-and-white text about 9% tall, wow-drama a
 * small gold logo right at the edge, rongyok a line of Thai. Writing a colour rule per site was tried
 * and it does not hold up — measured on real wow-drama covers, its gold barely registers at all,
 * while a large white Thai TITLE reads as a far stronger "mark" signal than the actual watermark.
 * A detector built that way flags artwork and misses branding, which is the wrong error twice over.
 *
 * What IS reliable is position: the ones worth removing sit flush against the bottom edge. So for a
 * cover taken from a site known to brand that way, the strip comes off unconditionally. Losing the
 * bottom 7% of a poster costs a sliver of background; keeping it costs a rival's logo on our page.
 *
 * The height is restored afterwards so covers stay a uniform shape in the grid — a 7% vertical
 * stretch is not perceptible on a poster, whereas a row of cards at slightly different aspect ratios
 * is immediately obvious.
 */
class PosterCrop
{
    /** Share of height removed. Comfortably clears an edge logo without eating the artwork. */
    public const DEFAULT_STRIP = 0.07;

    /** Sources that brand along the bottom edge, so anything taken from them is trimmed on arrival. */
    private const BOTTOM_BRANDED = ['wowdrama', 'goseries4k', '9nung'];

    public static function brandsBottomEdge(?string $source): bool
    {
        return $source !== null && in_array($source, self::BOTTOM_BRANDED, true);
    }

    /**
     * Trim the bottom strip and stretch back to the original height.
     *
     * @param  float  $strip  share of the height to remove (0.02–0.20)
     * @return string|null  re-encoded WebP bytes, or null when the image can't be handled
     */
    public static function trimBottom(string $bytes, float $strip = self::DEFAULT_STRIP, int $quality = 82): ?string
    {
        $strip = max(0.02, min(0.20, $strip));

        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $keep = (int) round($h * (1 - $strip));

        // Too small to lose anything from — hand it back untouched rather than damage it.
        if ($w < 120 || $keep < 100) {
            imagedestroy($img);

            return null;
        }

        $out = imagecreatetruecolor($w, $h);
        // Copy the kept region back over the FULL original height in one resample: the crop and the
        // stretch happen together, so the image is only resampled once.
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, $w, $keep);
        imagedestroy($img);

        ob_start();
        $ok = @imagewebp($out, null, $quality);
        $encoded = (string) ob_get_clean();
        imagedestroy($out);

        // imagewebp can report success and still produce nothing usable — trust the bytes, not the
        // return value (the same trap ImageStore::putWebp documents).
        if (! $ok || strlen($encoded) < 100 || substr($encoded, 0, 4) !== 'RIFF') {
            return null;
        }

        return $encoded;
    }
}
