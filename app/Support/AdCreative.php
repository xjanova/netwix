<?php

namespace App\Support;

use App\Models\AdPlacement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns an advertiser's upload into a banner we are willing to serve.
 *
 * The security requirement the owner set ("มีระบบป้องกันภาพ ฝังโค๊ด") is met by never serving the
 * uploaded bytes at all. The file is decoded into a pixel buffer and RE-ENCODED from scratch, so
 * whatever rode along in it — EXIF, an appended ZIP, PHP in a comment chunk, a polyglot GIF/JS — is
 * simply not present in the output. That single decision is worth more than any amount of scanning:
 * scanning asks "does this look dangerous", re-encoding makes the question moot.
 *
 * Around that:
 *  - the type is decided by DECODING, not by the filename or the browser's Content-Type;
 *  - SVG is refused outright (it is a script container, not an image);
 *  - dimensions are capped before allocation, so a "decompression bomb" can't exhaust memory;
 *  - the crop the buyer chose is applied here, server-side, then scaled to the placement's exact
 *    size — the client's crop box is a UI, never the authority on the output.
 *
 * Animated GIFs become a still frame: this server has GD but no Imagick, and GD cannot write
 * animation. Losing the animation is the acceptable half of that trade; serving un-re-encoded bytes
 * would not be.
 */
class AdCreative
{
    /** Refuse anything larger than this before allocating a canvas (guards decompression bombs). */
    private const MAX_SOURCE_PIXELS = 50_000_000;   // 50 MP

    /**
     * Validate, crop, re-encode and store. Returns the stored path (public disk), or throws with a
     * message meant for the advertiser.
     *
     * @param  array{x:float,y:float,w:float,h:float}|null  $crop  source-pixel crop chosen in the UI
     *
     * @throws \RuntimeException
     */
    public function store(UploadedFile $file, AdPlacement $placement, ?array $crop = null): string
    {
        if ($file->getSize() > $placement->max_upload_bytes) {
            throw new \RuntimeException('ไฟล์ใหญ่เกิน '.$placement->max_upload_kb.' KB');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        if ($bytes === '') {
            throw new \RuntimeException('อ่านไฟล์ไม่ได้');
        }

        // Decide the type from the CONTENT. A file called banner.png that is actually an HTML
        // document must be refused here, not discovered later by a browser sniffing it.
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new \RuntimeException('ไฟล์นี้ไม่ใช่รูปภาพที่อ่านได้ (รองรับ JPG, PNG, GIF)');
        }
        [$w, $h, $type] = $info;

        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new \RuntimeException('รองรับเฉพาะไฟล์ JPG, PNG, GIF');
        }
        if ($w < 1 || $h < 1 || ($w * $h) > self::MAX_SOURCE_PIXELS) {
            throw new \RuntimeException('ขนาดภาพไม่ถูกต้อง หรือใหญ่เกินไป');
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new \RuntimeException('ถอดรหัสภาพไม่สำเร็จ');
        }

        try {
            $out = $this->render($src, $w, $h, $placement, $crop);
        } finally {
            imagedestroy($src);
        }

        $path = 'media/ad-creatives/'.date('Y/m').'/'.bin2hex(random_bytes(12)).'.webp';
        Storage::disk('public')->put($path, $out);

        return $path;
    }

    /**
     * Crop (if the buyer positioned one) then fit to the placement's exact pixel size, and encode to
     * WebP. The canvas is filled first so a transparent PNG doesn't come out with black edges.
     *
     * @param  \GdImage  $src
     * @param  array{x:float,y:float,w:float,h:float}|null  $crop
     */
    private function render($src, int $w, int $h, AdPlacement $placement, ?array $crop): string
    {
        // Clamp the requested crop into the image. The values arrive from the browser, so they are
        // treated as a suggestion: a bad one yields a sane picture rather than an error or a crash.
        $cx = (int) max(0, min($w - 1, (int) round($crop['x'] ?? 0)));
        $cy = (int) max(0, min($h - 1, (int) round($crop['y'] ?? 0)));
        $cw = (int) max(1, min($w - $cx, (int) round($crop['w'] ?? $w)));
        $ch = (int) max(1, min($h - $cy, (int) round($crop['h'] ?? $h)));

        $tw = max(1, (int) $placement->width);
        $th = max(1, (int) $placement->height);

        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 12, 8, 18));   // matches the site's ink
        imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $tw, $th, $cw, $ch);

        ob_start();
        imagewebp($dst, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($dst);

        if ($out === '') {
            throw new \RuntimeException('บันทึกภาพไม่สำเร็จ');
        }

        return $out;
    }

    /** Remove a stored creative; never throws (a leftover file is not worth failing a request). */
    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
