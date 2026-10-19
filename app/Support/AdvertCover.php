<?php

namespace App\Support;

/**
 * RongYok's anti-hotlink advert — the green "เนื้อหานี้ของโรงหยก ดูฟรีเต็มๆ rongyok.com" card.
 *
 * Measured 2026-10-19: a poster request that carries ANOTHER site's Referer (a viewer's browser on
 * netwix.online, or a server fetch that sends a foreign Referer) is answered `302 Location:
 * /images/anti1.png`; with rongyok's own Referer or none, the same URL returns the real artwork. So
 * a title we leave hotlinked wears a rival's advert on our pages, and any fetch that follows the
 * redirect would happily store the advert as the cover. Three independent checks keep it out:
 *
 *   1. never hand a browser a rongyok image URL at all ([self::isAdvertHotlink]);
 *   2. never accept a download that was redirected to the anti* image ([self::isAdvertRedirect]);
 *   3. never store bytes that ARE the advert ([self::looksLikeAdvert]) — exact hash of the two known
 *      cards from any host, plus, for images from rongyok itself, a green-dominance test that catches
 *      a re-encoded or new variant. On this scale the cards measure 0.60 (anti2) and 0.71 (anti1);
 *      every one of the 3,744 rongyok covers we store measured ≤ 0.49. The colour test is NOT safe
 *      for other sources — genuine anime and drama posters reach 0.77 — so it never runs on them.
 */
final class AdvertCover
{
    public const HOSTS = ['rongyok.com', 'www.rongyok.com'];

    /** md5 of the card images as served (anti1.png, anti2.png, 1086×1448 PNG). */
    public const KNOWN_MD5 = ['50f0fadde3eaa21dc95493d2b81e1067', '89b8d44d68ce766c8c60e0e83b65618c'];

    /** Green share above which any image is treated as the card. */
    public const GREEN_ANY = 0.55;

    /** Lower bar for an image with the card's exact 1086×1448 size. */
    public const GREEN_CARD_SIZE = 0.35;

    /** A URL that a viewer's browser would get the advert for instead of the picture. */
    public static function isAdvertHotlink(?string $url): bool
    {
        if (! $url || ! str_starts_with($url, 'http')) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::HOSTS, true);
    }

    /** The address a download actually ended at is the anti-hotlink card. */
    public static function isAdvertRedirect(?string $effectiveUrl): bool
    {
        return self::isAdvertHotlink($effectiveUrl)
            && (bool) preg_match('~^/images/anti\d*\.(?:png|jpe?g|webp|gif)$~i', (string) parse_url((string) $effectiveUrl, PHP_URL_PATH));
    }

    /** @param  string|null  $fromUrl  where the bytes came from; the colour test only runs for rongyok images */
    public static function looksLikeAdvert(string $bytes, ?string $fromUrl = null): bool
    {
        if (in_array(md5($bytes), self::KNOWN_MD5, true)) {
            return true;
        }
        if (! self::isAdvertHotlink($fromUrl)) {
            return false;
        }
        $size = @getimagesizefromstring($bytes);
        $green = self::greenShare($bytes);
        if ($green === null) {
            return false;
        }

        return $green >= self::GREEN_ANY
            || ($size && $size[0] === 1086 && $size[1] === 1448 && $green >= self::GREEN_CARD_SIZE);
    }

    /** Share of clearly green pixels on a 32×32 thumbnail, or null when the bytes are not decodable. */
    public static function greenShare(string $bytes): ?float
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        $thumb = imagecreatetruecolor(32, 32);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, 32, 32, imagesx($image), imagesy($image));
        $green = 0;
        for ($x = 0; $x < 32; $x++) {
            for ($y = 0; $y < 32; $y++) {
                $rgb = imagecolorat($thumb, $x, $y);
                [$r, $g, $b] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                if ($g >= 35 && $g > $r * 1.2 && $g > $b * 1.15) {
                    $green++;
                }
            }
        }
        imagedestroy($thumb);
        imagedestroy($image);

        return $green / 1024;
    }
}
