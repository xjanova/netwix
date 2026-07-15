<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ClipOutro;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "ท้ายคลิป" — the branding card ClipOutro appends to every marketing clip. This only writes
 * settings; the drawing (and its caching) lives in ClipOutro, so the preview here renders
 * exactly the same pixels the cutter will burn in.
 */
class ClipOutroController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'clip_outro_text' => ['nullable', 'string', 'max:200'],
            'clip_outro_seconds' => ['required', 'integer', 'between:2,10'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        Setting::write('clip_outro_enabled', $request->boolean('clip_outro_enabled') ? '1' : '0');
        Setting::write('clip_outro_text', trim((string) ($data['clip_outro_text'] ?? '')) ?: null);
        Setting::write('clip_outro_seconds', (string) $data['clip_outro_seconds']);

        if ($request->hasFile('logo')) {
            // Kept as PNG-capable WebP on the public disk; ClipOutro reads it off local disk.
            $path = ImageStore::putWebp(
                (string) file_get_contents($request->file('logo')->getRealPath()),
                'media/clip-outro',
                'logo-'.now()->format('YmdHis'),
                1400,
            );
            if ($path) {
                Setting::write('clip_outro_logo', $path);
            }
        }

        if ($request->boolean('reset_logo')) {
            Setting::write('clip_outro_logo', null);   // back to the shipped NetWix wordmark
        }

        return back()->with('status', 'บันทึกท้ายคลิปแล้ว — คลิปที่ตัดหลังจากนี้จะใช้ค่าใหม่');
    }

    /** Render the card as it will actually appear, for the admin preview. */
    public function preview(Request $request, ClipOutro $outro): Response
    {
        $aspect = in_array($request->query('aspect'), ['9:16', '1:1', '16:9'], true)
            ? (string) $request->query('aspect')
            : '9:16';

        $path = $outro->preview($aspect);
        abort_if($path === null, 404, 'ยังสร้างภาพท้ายคลิปไม่ได้ (ไม่พบโลโก้หรือฟอนต์)');

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',   // must reflect an edit made a second ago
        ]);
    }
}
