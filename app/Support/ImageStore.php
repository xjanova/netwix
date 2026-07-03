<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Stores admin-provided images (uploaded files or grabbed video frames) as WebP on the public disk —
 * smaller + faster to serve than JPEG/PNG. Accepts any GD-readable format, downscales oversized
 * images, and falls back to the raw bytes if WebP encoding isn't available.
 */
class ImageStore
{
    /** Validate + decode a `data:image/...;base64,...` URL to raw bytes, or null if invalid. */
    public static function decodeDataUrl(string $dataUrl, int $maxBytes = 8_000_000): ?string
    {
        if (! preg_match('~^data:image/[a-z0-9.+-]+;base64,~i', $dataUrl)) {
            return null;
        }
        $bin = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bin === false || strlen($bin) < 200 || strlen($bin) > $maxBytes) {
            return null;
        }

        return $bin;
    }

    /**
     * Decode image bytes, downscale to $maxDim on the long side, and save as WebP on the public disk.
     * Returns the relative path (e.g. "media/thumbs/12.webp") or null if the bytes aren't an image.
     */
    public static function putWebp(string $bytes, string $dir, string $basename, int $maxDim = 1600, int $quality = 82): ?string
    {
        $dir = trim($dir, '/');
        if (! function_exists('imagecreatefromstring')) {
            return self::putRaw($bytes, $dir, $basename);
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;   // not a decodable image
        }
        $img = self::downscale($img, $maxDim);

        if (! function_exists('imagewebp')) {
            imagedestroy($img);

            return self::putRaw($bytes, $dir, $basename);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'nxwebp');
        $ok = @imagewebp($img, $tmp, $quality);
        imagedestroy($img);
        if (! $ok) {
            @unlink($tmp);

            return self::putRaw($bytes, $dir, $basename);
        }
        $path = "{$dir}/{$basename}.webp";
        Storage::disk('public')->put($path, (string) file_get_contents($tmp));
        @unlink($tmp);

        return $path;
    }

    private static function downscale(\GdImage $img, int $maxDim): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) <= $maxDim) {
            return $img;
        }
        $scale = $maxDim / max($w, $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        return $dst;
    }

    /** Fallback when WebP isn't available — keep the original bytes under a correct extension. */
    private static function putRaw(string $bytes, string $dir, string $basename): ?string
    {
        $ext = 'jpg';
        if (function_exists('getimagesizefromstring') && ($info = @getimagesizefromstring($bytes))) {
            $ext = match ($info[2] ?? 0) {
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_GIF => 'gif',
                IMAGETYPE_WEBP => 'webp',
                IMAGETYPE_BMP => 'bmp',
                default => 'jpg',
            };
        } elseif (str_starts_with($bytes, "\x89PNG")) {
            $ext = 'png';
        } elseif (str_starts_with($bytes, 'GIF8')) {
            $ext = 'gif';
        } elseif (substr($bytes, 8, 4) === 'WEBP') {
            $ext = 'webp';
        }
        $path = "{$dir}/{$basename}.{$ext}";
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
}
