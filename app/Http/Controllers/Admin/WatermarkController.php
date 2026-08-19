<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Setting;
use App\Support\PosterWatermark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "ลายน้ำบนปก" — position, weight and content of the mark burned into every cover.
 *
 * The settings are deliberately visual rather than numeric: where a mark belongs on a poster is a
 * judgement about how the catalogue looks, and nobody can make it from a pair of coordinates. So the
 * admin drags it on a real cover and sees the actual rendered result — [self::preview] runs the same
 * [PosterWatermark::apply] the storage path uses, on unsaved values, so what is on screen is exactly
 * what will be written.
 */
class WatermarkController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.watermark.index', [
            'cfg' => PosterWatermark::config(),
            'logos' => $this->availableLogos(),
            'samples' => $this->sampleCovers(),
            'fontAvailable' => PosterWatermark::logoPath() !== null,
            'markedCount' => $this->markedCount(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'auto_contrast' => ['nullable', 'boolean'],
            'mode' => ['required', 'in:logo,text'],
            'text' => ['nullable', 'string', 'max:60'],
            'logo' => ['nullable', 'string', 'max:191'],
            'x' => ['required', 'integer', 'between:0,100'],
            'y' => ['required', 'integer', 'between:0,100'],
            'width' => ['required', 'integer', 'between:5,100'],
            'opacity' => ['required', 'integer', 'between:3,100'],
        ]);

        foreach (PosterWatermark::DEFAULTS as $key => $default) {
            $value = match ($key) {
                'enabled', 'auto_contrast' => (bool) ($data[$key] ?? false),
                default => $data[$key] ?? $default,
            };
            Setting::write('poster_watermark_'.$key, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return back()->with('status', 'บันทึกการตั้งค่าลายน้ำแล้ว — ปกที่บันทึกใหม่จะใช้ค่านี้ทันที');
    }

    /**
     * Render one real cover with the given (possibly unsaved) settings and return it as an image.
     *
     * Nothing is stored: this reads a cover, stamps a copy in memory and streams it back, so the live
     * preview can update on every drag without touching the catalogue.
     */
    public function preview(Request $request): Response
    {
        $cfg = array_merge(PosterWatermark::config(), array_filter([
            'mode' => $request->query('mode'),
            'text' => $request->query('text'),
            'logo' => $request->query('logo'),
            'x' => $request->has('x') ? (int) $request->query('x') : null,
            'y' => $request->has('y') ? (int) $request->query('y') : null,
            'width' => $request->has('width') ? (int) $request->query('width') : null,
            'opacity' => $request->has('opacity') ? (int) $request->query('opacity') : null,
            'auto_contrast' => $request->has('auto_contrast')
                ? filter_var($request->query('auto_contrast'), FILTER_VALIDATE_BOOL)
                : null,
        ], fn ($v) => $v !== null && $v !== ''));

        $path = (string) $request->query('cover', '');
        // Only ever read from our own poster directory — the path arrives from the browser.
        if (! str_starts_with($path, 'media/posters/') || ! Storage::disk('public')->exists($path)) {
            $path = (string) ($this->sampleCovers()[0]['path'] ?? '');
        }
        $bytes = $path !== '' ? Storage::disk('public')->get($path) : null;
        $img = $bytes ? @imagecreatefromstring($bytes) : false;
        if ($img === false) {
            return response('', 404);
        }

        PosterWatermark::apply($img, $cfg);

        ob_start();
        imagewebp($img, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return response($out, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'no-store',   // it changes on every drag
        ]);
    }

    /** Admin: upload a logo PNG to use as the mark. */
    public function uploadLogo(Request $request): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:6000000']]);

        $bin = \App\Support\ImageStore::decodeDataUrl($data['image']);
        if ($bin === null) {
            return response()->json(['ok' => false, 'error' => 'ไฟล์ไม่ใช่รูปภาพ'], 422);
        }
        // A watermark needs transparency, so it is stored as PNG rather than re-encoded to WebP —
        // and re-drawn through GD so nothing but pixels survives the upload.
        $img = @imagecreatefromstring($bin);
        if ($img === false) {
            return response()->json(['ok' => false, 'error' => 'อ่านรูปไม่ได้'], 422);
        }
        imagealphablending($img, false);
        imagesavealpha($img, true);

        $name = 'assets/watermarks/'.Str::random(10).'.png';
        $full = public_path($name);
        if (! is_dir(dirname($full))) {
            @mkdir(dirname($full), 0755, true);
        }
        imagepng($img, $full);
        imagedestroy($img);

        return response()->json(['ok' => true, 'logo' => $name]);
    }

    /** Logo files an admin can pick: the brand assets plus anything uploaded here. */
    private function availableLogos(): array
    {
        $out = [];
        foreach (['assets', 'assets/watermarks'] as $dir) {
            foreach (glob(public_path($dir).'/*.png') ?: [] as $file) {
                $out[] = $dir.'/'.basename($file);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A few real covers to preview against — deliberately spread across brightness, because a mark
     * that reads on a pale poster can vanish on a dark one.
     *
     * @return array<int,array{id:int,title:string,path:string}>
     */
    private function sampleCovers(): array
    {
        return Content::withoutGlobalScopes()
            ->where('poster_path', 'like', 'media/posters/%')
            ->orderByDesc('views')
            ->limit(6)
            ->get(['id', 'title', 'poster_path'])
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'path' => $c->poster_path])
            ->all();
    }

    private function markedCount(): int
    {
        return Content::withoutGlobalScopes()->whereNotNull('poster_watermarked_at')->count();
    }
}
