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

    /**
     * Store a cover/poster under a UNIQUE filename each call ("{basename}-{version}.webp") and delete
     * the previous file. A fresh PATH — not just a "?t=" query — is the only reliable way to make a
     * regenerated image show IMMEDIATELY: Cloudflare (and the browser) key their cache on the path and
     * routinely ignore the query string on a static asset, so a same-name overwrite keeps serving the
     * stale image. Pass the currently-stored value (relative path or a full URL) as $previous so the
     * old file is cleaned up. Returns the relative path to store, or null on failure.
     */
    public static function putCover(string $bytes, string $dir, string $basename, ?string $previous = null, int $maxDim = 1600, int $quality = 82): ?string
    {
        $version = bin2hex(random_bytes(4));   // 8 hex chars — unique per (re)generation
        $path = self::putWebp($bytes, $dir, "{$basename}-{$version}", $maxDim, $quality);
        if ($path !== null && $previous !== null && $previous !== '') {
            self::deleteStored($previous, $dir);
        }

        return $path;
    }

    /** Best-effort delete of a previously-stored image (accepts a relative path or a full "?t=" URL). */
    private static function deleteStored(string $previous, string $dir): void
    {
        try {
            $p = ltrim((string) (parse_url($previous, PHP_URL_PATH) ?: $previous), '/');
            $p = preg_replace('~^storage/~', '', $p);   // public-disk URLs are served under /storage
            $dir = trim($dir, '/');
            // Only ever delete an image inside the expected media dir — never anything else.
            if (str_starts_with($p, $dir.'/') && preg_match('~\.(webp|jpe?g|png|gif|bmp)$~i', $p)) {
                Storage::disk('public')->delete($p);
            }
        } catch (\Throwable $e) {
            // orphan cleanup is best-effort — never fail a save over it
        }
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
