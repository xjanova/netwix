<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppBanner;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Admin CRUD for the mobile app's home-screen promo banners ("แบนเนอร์ในแอป").
 * Mirrors AdController's upload handling (image → WebP via ImageStore); the app
 * reads these via GET /api/app/banners.
 */
class AppBannerController extends Controller
{
    public function index(): View
    {
        return view('admin.app-banners.index', [
            'banners' => AppBanner::orderByDesc('sort')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new AppBanner);

        return back()->with('status', 'เพิ่มแบนเนอร์แล้ว');
    }

    public function update(Request $request, AppBanner $banner): RedirectResponse
    {
        $this->save($request, $banner);

        return back()->with('status', 'บันทึกแบนเนอร์แล้ว');
    }

    public function toggle(AppBanner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('status', $banner->is_active ? 'เปิดแบนเนอร์แล้ว' : 'ปิดแบนเนอร์แล้ว');
    }

    public function destroy(AppBanner $banner): RedirectResponse
    {
        $this->deleteFile($banner->image_path);
        $banner->delete();

        return back()->with('status', 'ลบแบนเนอร์แล้ว');
    }

    private function save(Request $request, AppBanner $banner): void
    {
        // Require a creative only when CREATING and no URL was given (an edit may keep its existing one).
        $fileRule = ($banner->exists || $request->filled('image_url')) ? 'nullable' : 'required';
        $endRules = ['nullable', 'date'];
        if ($request->filled('starts_at')) {
            $endRules[] = 'after_or_equal:starts_at';
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'image_file' => [$fileRule, 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => $endRules,
            'sort' => ['nullable', 'integer', 'between:0,100000'],
        ], [
            'image_file.required' => 'กรุณาอัพโหลดรูปแบนเนอร์ หรือใส่ลิงก์รูป',
        ]);

        // Core fields first, so a NEW record has an id to name its uploaded file after.
        $banner->fill([
            'title' => $data['title'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'hide_for_pro' => $request->boolean('hide_for_pro'),
            'is_active' => $request->boolean('is_active'),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ])->save();

        if ($request->hasFile('image_file')) {
            $old = $banner->image_path;
            $basename = 'banner'.$banner->id.'-'.bin2hex(random_bytes(3));
            $path = ImageStore::putWebp((string) file_get_contents($request->file('image_file')->getRealPath()), 'media/app-banners', $basename, 1600);
            if ($path) {
                $banner->forceFill(['image_path' => $path, 'image_url' => null])->save();
                if ($old && $old !== $path) {
                    $this->deleteFile($old);
                }
            }
        } elseif (filled($data['image_url'] ?? null)) {
            $old = $banner->image_path;
            $banner->forceFill(['image_url' => $data['image_url'], 'image_path' => null])->save();
            $this->deleteFile($old);
        }
    }

    private function deleteFile(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            // best-effort file cleanup
        }
    }
}
