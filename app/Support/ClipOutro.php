<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * The branding card appended to the end of every marketing clip: NetWix logo + a
 * "watch the rest on NetWix / get the app" line. Admin-editable at /admin/clip-campaigns
 * (text, logo, seconds, on/off) — see Admin\ClipOutroController.
 *
 * Rendered with GD, NOT ffmpeg's drawtext. drawtext has no Thai shaping: it stacks vowels
 * and tone marks in the wrong place (the same trap the Fortune Bot Celtic image hit), while
 * GD's imagettftext with NotoSansThai renders Thai correctly — proven on this box.
 *
 * The card is cached per (aspect, settings) — the cache key is a hash of everything that can
 * change the pixels, so editing the text or swapping the logo simply produces a new key and
 * the next clip picks it up. Nothing has to be purged by hand.
 */
class ClipOutro
{
    public const DEFAULT_TEXT = "ดูเต็มๆ ฟรี ไม่มีโฆษณา\nnetwix.online · โหลดแอป NetWix";

    private const DEFAULT_SECONDS = 4;

    /** Cards live outside the public disk: they are an ffmpeg input, not a served asset. */
    private const CACHE_DIR = 'clip-outro';

    /** Bump when the drawing code changes, so cached cards from an older look are re-rendered. */
    private const RENDER_VERSION = 1;

    public function enabled(): bool
    {
        return Setting::flag('clip_outro_enabled', true);
    }

    /** How long the card shows for, in seconds. */
    public function seconds(): int
    {
        return max(2, min(10, (int) (Setting::get('clip_outro_seconds') ?: self::DEFAULT_SECONDS)));
    }

    public function text(): string
    {
        $text = trim((string) (Setting::get('clip_outro_text') ?? ''));

        return $text !== '' ? $text : self::DEFAULT_TEXT;
    }

    /** Absolute path of the logo to burn in: the admin's upload, else the shipped wordmark. */
    public function logoPath(): ?string
    {
        $custom = (string) (Setting::get('clip_outro_logo') ?? '');
        if ($custom !== '') {
            $path = storage_path('app/public/'.ltrim($custom, '/'));
            if (is_file($path)) {
                return $path;
            }
        }
        $default = public_path('assets/netwix-wordmark.png');

        return is_file($default) ? $default : null;
    }

    /**
     * A TTF that can actually draw Thai. The env override wins; the rest are the places the
     * font is known to live on this box. Without one we skip the text (logo-only card) rather
     * than render tofu boxes at the end of every clip.
     */
    public function fontPath(): ?string
    {
        $candidates = [
            (string) config('services.ffmpeg.font', ''),
            resource_path('fonts/NotoSansThai-Bold.ttf'),
            '/home/admin/fonts/NotoSansThai-Bold.ttf',
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Path to the rendered card for this aspect, or null when the outro is off / can't be drawn
     * (no GD, no logo, no font). Callers treat null as "no outro" and carry on — a branding
     * nicety must never fail a clip.
     */
    public function card(string $aspect): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->render($aspect);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Force a card for previewing in the admin, ignoring the on/off switch. */
    public function preview(string $aspect = '9:16'): ?string
    {
        try {
            return $this->render($aspect);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    // ---- rendering ----------------------------------------------------------

    private function render(string $aspect): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        [$w, $h] = match ($aspect) {
            '1:1' => [1080, 1080],
            '16:9' => [1280, 720],
            default => [1080, 1920],   // 9:16 — rendered above the 720x1280 output, then downscaled
        };

        $logo = $this->logoPath();
        $font = $this->fontPath();
        if ($logo === null && $font === null) {
            return null;   // nothing to draw
        }

        $path = $this->cachePath($aspect, $w, $h, $logo, $font);
        if (is_file($path)) {
            return $path;
        }

        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        $this->drawBackground($img, $w, $h);
        $bottom = $this->drawLogo($img, $w, $h, $logo);
        $this->drawText($img, $w, $h, $bottom, $font);
        $this->drawAccentBar($img, $w, $h);

        @mkdir(dirname($path), 0775, true);
        $ok = imagepng($img, $path, 6);
        imagedestroy($img);

        return $ok && is_file($path) ? $path : null;
    }

    /** Deep-purple → near-black diagonal wash (drawn small, then smoothly upscaled). */
    private function drawBackground($img, int $w, int $h): void
    {
        $grad = imagecreatetruecolor(48, 48);
        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < 48; $x++) {
                $t = ($x + $y) / 94;
                imagesetpixel($grad, $x, $y, imagecolorallocate(
                    $grad,
                    (int) round(27 + (7 - 27) * $t),
                    (int) round(11 + (5 - 11) * $t),
                    (int) round(47 + (9 - 47) * $t),
                ));
            }
        }
        imagecopyresampled($img, $grad, 0, 0, 0, 0, $w, $h, 48, 48);
        imagedestroy($grad);

        // Soft brand glow behind the logo so the card doesn't read as a flat black slate.
        $glow = imagecolorallocatealpha($img, 176, 38, 255, 110);
        imagefilledellipse($img, (int) ($w / 2), (int) ($h * 0.40), (int) ($w * 1.05), (int) ($h * 0.30), $glow);
    }

    /** Centre the logo in the upper half; returns the y where text may start. */
    private function drawLogo($img, int $w, int $h, ?string $logo): int
    {
        if ($logo === null) {
            return (int) ($h * 0.42);
        }

        $src = @imagecreatefromstring((string) @file_get_contents($logo));
        if ($src === false) {
            return (int) ($h * 0.42);
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        $targetW = (int) ($w * 0.72);
        $targetH = (int) round($targetW * $sh / $sw);
        $maxH = (int) ($h * 0.26);
        if ($targetH > $maxH) {
            $targetH = $maxH;
            $targetW = (int) round($targetH * $sw / $sh);
        }
        $x = (int) (($w - $targetW) / 2);
        $y = (int) ($h * 0.40 - $targetH / 2);

        imagealphablending($img, true);
        imagecopyresampled($img, $src, $x, $y, 0, 0, $targetW, $targetH, $sw, $sh);
        imagedestroy($src);

        return $y + $targetH;
    }

    /** Centred Thai lines under the logo. */
    private function drawText($img, int $w, int $h, int $top, ?string $font): void
    {
        if ($font === null) {
            return;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->text()))));
        if (! $lines) {
            return;
        }

        $size = max(18, (int) round($w / 26));
        $lineHeight = (int) round($size * 1.75);
        $y = max($top + (int) ($h * 0.07), (int) ($h * 0.58));
        $white = imagecolorallocate($img, 255, 255, 255);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 70);

        foreach (array_slice($lines, 0, 4) as $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $x = (int) (($w - ($box[2] - $box[0])) / 2);
            imagettftext($img, $size, 0, $x + 2, $y + 3, $shadow, $font, $line);
            imagettftext($img, $size, 0, $x, $y, $white, $font, $line);
            $y += $lineHeight;
        }
    }

    /** Brand gradient strip along the bottom edge (pink → purple, same as the site). */
    private function drawAccentBar($img, int $w, int $h): void
    {
        $barH = max(6, (int) round($h * 0.012));
        $bar = imagecreatetruecolor(64, 1);
        for ($x = 0; $x < 64; $x++) {
            $t = $x / 63;
            imagesetpixel($bar, $x, 0, imagecolorallocate(
                $bar,
                (int) round(255 + (176 - 255) * $t),   // #ff2d55 → #b026ff
                (int) round(45 + (38 - 45) * $t),
                (int) round(85 + (255 - 85) * $t),
            ));
        }
        imagecopyresampled($img, $bar, 0, $h - $barH, 0, 0, $w, $barH, 64, 1);
        imagedestroy($bar);
    }

    /** Cache file keyed by everything that can change the pixels. */
    private function cachePath(string $aspect, int $w, int $h, ?string $logo, ?string $font): string
    {
        $key = md5(implode('|', [
            self::RENDER_VERSION,
            $this->text(),
            $logo ?? '-',
            $logo && is_file($logo) ? (string) filemtime($logo) : '-',
            $font ?? '-',
            $w.'x'.$h,
        ]));

        $slug = str_replace(':', '-', $aspect);

        return storage_path('app/'.self::CACHE_DIR."/{$slug}-{$key}.png");
    }
}
