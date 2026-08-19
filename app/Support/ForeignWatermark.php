<?php

namespace App\Support;

/**
 * Detects another site's watermark burned into a cover we stored.
 *
 * goseries4k stamps "GO" in orange plus "SERIES 4K" in white across roughly 9% of the poster height,
 * near the bottom. Because it is the same artwork placed the same way every time, the giveaway is not
 * "lots of white" or "lots of orange" — it is a NARROW, REPEATED combination of both. Measured on
 * eight covers confirmed marked by hand, the orange share landed between 0.0295 and 0.0319 on every
 * single one; that consistency is the signature.
 *
 * Scoring by a sum was tried first and rejected: a 24hdx poster with a pale background scored 0.47,
 * far above every genuinely marked cover, and would have been "cleaned" — destroying a good poster to
 * fix one that was never broken. Requiring both channels to sit INSIDE their bands instead separates
 * the two cleanly.
 *
 * ⚠️ KNOWN LIMIT, and it matters for how this is used. Measured over 200 random covers per source:
 * 5.1% of goseries4k trip it, 0% of the 24hdx control do. Precision looks excellent, but an earlier
 * independent measurement put the true prevalence nearer 15%, so this finds roughly a THIRD of what
 * is out there — it only looks at the bottom band, so a mark placed over the middle of the artwork
 * (which does happen) is invisible to it, as is one rendered at a different size.
 *
 * So it is deliberately used as a high-confidence FILTER, never as a census: what it flags is worth
 * acting on, what it misses is left for a human to catch through the missing-covers queue. Widening
 * the bands to catch more would start eating clean posters, which is the worse error.
 */
class ForeignWatermark
{
    /** Fraction of the image height, measured up from the bottom, that the mark occupies. */
    private const BAND = 0.18;

    /** Bands the two colour shares must BOTH fall inside. Derived from hand-confirmed covers. */
    private const ORANGE_MIN = 0.024;

    private const ORANGE_MAX = 0.040;

    private const WHITE_MIN = 0.045;

    private const WHITE_MAX = 0.090;

    /**
     * Colour shares in the bottom band, or null when the file can't be read.
     *
     * @return array{white:float,orange:float}|null
     */
    public static function measure(string $absolutePath): ?array
    {
        if (! is_readable($absolutePath)) {
            return null;
        }
        $img = @imagecreatefromstring((string) @file_get_contents($absolutePath));
        if ($img === false) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $y0 = (int) ($h * (1 - self::BAND));
        $white = 0;
        $orange = 0;
        $n = 0;

        // Every other pixel in both directions — a quarter of the work, and the mark is far larger
        // than the sampling step.
        for ($y = $y0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $n++;

                if ($r > 225 && $g > 225 && $b > 220) {
                    $white++;
                } elseif ($r > 200 && $g > 90 && $g < 190 && $b < 80) {
                    $orange++;   // the specific orange of their "GO"
                }
            }
        }
        imagedestroy($img);

        return $n > 0 ? ['white' => $white / $n, 'orange' => $orange / $n] : null;
    }

    /** True when the cover carries goseries4k's mark, judged conservatively. */
    public static function detected(string $absolutePath): bool
    {
        $s = self::measure($absolutePath);
        if ($s === null) {
            return false;
        }

        return $s['orange'] >= self::ORANGE_MIN && $s['orange'] <= self::ORANGE_MAX
            && $s['white'] >= self::WHITE_MIN && $s['white'] <= self::WHITE_MAX;
    }
}
