<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Stamps a mark — the NetWix logo, or text you choose — into a cover image.
 *
 * WHY IT EXISTS. A cover is the one asset that gets copied by hand: right-click, save, re-upload.
 * None of the usual defences reach that. Our posters are served from Cloudflare's edge
 * (`cf-cache-status: HIT`), so a Referer check in PHP never runs, and the origin answers on its bare
 * IP, so an edge rule can be walked around too (both measured 2026-08-19). A mark inside the pixels
 * needs no request to inspect; it travels with the file wherever it goes.
 *
 * WHY THE MIDDLE, BY DEFAULT. A corner tag is cropped off in seconds. A mark across the centre cannot
 * be removed without destroying the artwork under it. The cost is that it has to stay quiet enough
 * not to spoil the poster — which is what the auto-contrast is for, and why the default strength is
 * only a fifth.
 *
 * EVERYTHING IS TUNABLE FROM THE ADMIN, because the right position and weight are a judgement about
 * how the catalogue looks, not something to settle in code: position (dragged on a live preview),
 * size, opacity, auto-contrast on/off, which logo file, or plain text instead of a logo.
 *
 * Both rongyok and goseries4k do this to us — goseries4k burns "GO SERIES 4K" over roughly 15% of
 * its catalogue, sometimes straight across the faces. Their execution is what NOT to copy: it wrecks
 * the artwork on their own site too.
 */
class PosterWatermark
{
    /** Defaults — every one of these is overridable from the admin page. */
    public const DEFAULTS = [
        'enabled' => false,
        'mode' => 'logo',        // logo | text
        'text' => 'netwix.online',
        'logo' => 'assets/netwix-logo2.png',
        'x' => 50,               // centre of the mark, as a % of width
        'y' => 50,               // centre of the mark, as a % of height
        'width' => 70,           // logo width as a % of image width
        'opacity' => 20,         // % of full opacity
        'auto_contrast' => true, // nudge opacity per image so every cover reads the same
    ];

    /** How far auto-contrast may move the configured opacity, in percentage points. */
    private const OPACITY_SWING = 9;

    /** Below this width the image is a thumbnail, not a cover — marking it just damages it. */
    private const MIN_IMAGE_WIDTH = 200;

    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    ];

    /** The whole configuration, defaults filled in. One read, so a caller can't get a half-set. */
    public static function config(): array
    {
        $out = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $key) {
            $stored = Setting::get('poster_watermark_'.$key, null);
            if ($stored === null || $stored === '') {
                continue;
            }
            $out[$key] = match ($key) {
                'enabled', 'auto_contrast' => filter_var($stored, FILTER_VALIDATE_BOOL),
                'x', 'y', 'width', 'opacity' => (int) $stored,
                default => (string) $stored,
            };
        }

        // Clamp everything a human could type wrong, so a bad value degrades instead of breaking.
        $out['x'] = max(0, min(100, $out['x']));
        $out['y'] = max(0, min(100, $out['y']));
        $out['width'] = max(5, min(100, $out['width']));
        $out['opacity'] = max(3, min(100, $out['opacity']));
        $out['mode'] = $out['mode'] === 'text' ? 'text' : 'logo';

        return $out;
    }

    public static function enabled(): bool
    {
        return (bool) self::config()['enabled'];
    }

    /** Absolute path of the configured logo, or null when it can't be read. */
    public static function logoPath(?array $cfg = null): ?string
    {
        $cfg ??= self::config();
        foreach ([$cfg['logo'], self::DEFAULTS['logo'], 'assets/netwix-icon.png'] as $rel) {
            $rel = trim((string) $rel);
            if ($rel === '') {
                continue;
            }
            $path = public_path(ltrim($rel, '/'));
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Draw the mark onto a GD image, in place. Returns false when it was skipped — image too small,
     * or the logo/font is unavailable — so a missing asset can never cost us the cover itself.
     *
     * @param  array|null  $cfg  pass a config to preview settings that aren't saved yet
     */
    public static function apply(\GdImage $img, ?array $cfg = null): bool
    {
        $cfg ??= self::config();

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < self::MIN_IMAGE_WIDTH) {
            return false;
        }

        return $cfg['mode'] === 'text'
            ? self::applyText($img, $cfg, $w, $h)
            : self::applyLogo($img, $cfg, $w, $h);
    }

    private static function applyLogo(\GdImage $img, array $cfg, int $w, int $h): bool
    {
        $logoPath = self::logoPath($cfg);
        if ($logoPath === null) {
            return false;
        }
        $logo = @imagecreatefrompng($logoPath) ?: @imagecreatefromstring((string) @file_get_contents($logoPath));
        if ($logo === false) {
            return false;
        }

        $lw = (int) max(1, round($w * $cfg['width'] / 100));
        $lh = (int) max(1, round(imagesy($logo) * $lw / imagesx($logo)));
        [$x, $y] = self::topLeft($cfg, $w, $h, $lw, $lh);

        $opacity = self::opacityFor($img, $cfg, $x, $y, $lw, $lh);

        // Resize with the alpha channel preserved — the default blending mode would flatten the
        // logo's transparency onto black and stamp a rectangle.
        $scaled = imagecreatetruecolor($lw, $lh);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        imagedestroy($logo);

        // IMG_FILTER_COLORIZE's 4th argument ADDS to each pixel's alpha — how a whole transparent PNG
        // is faded uniformly in GD.
        $fade = (int) round(127 * (1 - $opacity / 100));
        if ($fade > 0) {
            imagefilter($scaled, IMG_FILTER_COLORIZE, 0, 0, 0, $fade);
        }

        imagealphablending($img, true);
        imagecopy($img, $scaled, $x, $y, 0, 0, $lw, $lh);
        imagedestroy($scaled);

        return true;
    }

    private static function applyText(\GdImage $img, array $cfg, int $w, int $h): bool
    {
        $font = self::font();
        $text = trim((string) $cfg['text']);
        if ($font === null || $text === '') {
            return false;
        }

        // Size the text so the drawn string spans the configured width — the same knob means the same
        // thing whichever mode is chosen.
        $size = 10;
        $box = @imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return false;
        }
        $natural = max(1, abs($box[4] - $box[0]));
        $size = (int) max(8, round($size * ($w * $cfg['width'] / 100) / $natural));

        $box = @imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return false;
        }
        $tw = abs($box[4] - $box[0]);
        $th = abs($box[5] - $box[1]);
        [$x, $y] = self::topLeft($cfg, $w, $h, $tw, $th);

        $opacity = self::opacityFor($img, $cfg, $x, $y, $tw, $th);
        $alpha = (int) round(127 * (1 - $opacity / 100));

        imagealphablending($img, true);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, min(127, $alpha + 20));
        $ink = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
        if ($shadow === false || $ink === false) {
            return false;
        }
        $off = max(1, (int) round($size * 0.06));
        // imagettftext takes the text BASELINE, not the top edge.
        @imagettftext($img, $size, 0, $x + $off, $y + $th + $off, $shadow, $font, $text);
        @imagettftext($img, $size, 0, $x, $y + $th, $ink, $font, $text);

        return true;
    }

    /**
     * Top-left corner for a mark of this size, from the configured CENTRE point.
     *
     * The admin drags the middle of the mark, which is what a person means by "put it here"; the
     * result is clamped so dragging near an edge slides the mark flush instead of hanging it off.
     *
     * @return array{0:int,1:int}
     */
    private static function topLeft(array $cfg, int $w, int $h, int $markW, int $markH): array
    {
        $x = (int) round($w * $cfg['x'] / 100 - $markW / 2);
        $y = (int) round($h * $cfg['y'] / 100 - $markH / 2);

        return [max(0, min($w - $markW, $x)), max(0, min($h - $markH, $y))];
    }

    /**
     * Opacity for THIS cover: the configured value, optionally nudged by what sits behind the mark.
     *
     * A single fixed opacity does not survive a real catalogue. At 20% the logo reads clearly on pale
     * artwork and nearly vanishes on a saturated red or a dark poster, because the logo is itself
     * mid-toned red-violet. Mid-toned backgrounds are where it disappears, so those get the strongest
     * setting and the extremes are eased back — keeping the PERCEIVED strength even, not the number.
     */
    private static function opacityFor(\GdImage $img, array $cfg, int $x, int $y, int $markW, int $markH): int
    {
        $base = (int) $cfg['opacity'];
        if (! $cfg['auto_contrast']) {
            return $base;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $sum = 0;
        $n = 0;
        $step = max(4, (int) round(min($markW, $markH) / 24));   // sparse sample — runs per image

        for ($sy = max(0, $y); $sy < min($h, $y + $markH); $sy += $step) {
            for ($sx = max(0, $x); $sx < min($w, $x + $markW); $sx += $step) {
                $rgb = imagecolorat($img, $sx, $sy);
                // Rec. 601 luma — closer to perceived brightness than a flat RGB average.
                $sum += 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $n++;
            }
        }
        if ($n === 0) {
            return $base;
        }

        $distanceFromMid = abs($sum / $n - 128) / 128;   // 0 at mid-grey, 1 at pure black/white

        return max(3, min(100, $base + (int) round(self::OPACITY_SWING * (1 - $distanceFromMid))));
    }

    /** First usable TrueType font on this machine, or null (text mode then no-ops). */
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
