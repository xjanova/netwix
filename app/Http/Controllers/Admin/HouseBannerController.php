<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HouseBanner;
use App\Models\Setting;
use App\Support\Ads;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Admin CRUD for the site's OWN banner creatives ("โฆษณาสำรอง — แบนเนอร์ของเราเอง"), plus the two
 * knobs that govern them: rotation mode and the share of impressions they take from the ad network.
 * Upload handling mirrors [AppBannerController] (image → WebP via [ImageStore]).
 */
class HouseBannerController extends Controller
{
    public function index(): View
    {
        $banners = HouseBanner::orderByDesc('sort')->orderByDesc('id')->get();

        // What each weight ACTUALLY works out to. The owner asked to set frequency "เป็นเปอร์เซ็นต์",
        // and weights are relative — 30/30 is 50/50, not 30% — so show the real share rather than
        // leaving them to infer it. Computed per slot, since banners only compete within their own.
        $share = [];
        foreach ($banners->where('is_active', true)->groupBy('slot') as $slot => $group) {
            $total = max(1, $group->sum(fn (HouseBanner $b) => max(1, (int) $b->weight)));
            foreach ($group as $b) {
                $share[$b->id] = round(max(1, (int) $b->weight) / $total * 100);
            }
        }

        return view('admin.house-banners.index', [
            'banners' => $banners,
            'share' => $share,
            'slots' => Ads::SLOTS,
            'mode' => HouseBanner::mode(),
            'fill' => Ads::houseFill(),
            'adsOn' => Ads::enabled(),
        ]);
    }

    /** Rotation mode + the network/house split. Separate from per-banner edits. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'house_ads_mode' => ['required', 'in:'.implode(',', HouseBanner::MODES)],
            'house_ads_fill' => ['required', 'integer', 'between:0,100'],
        ]);

        Setting::write('house_ads_mode', $data['house_ads_mode']);
        Setting::write('house_ads_fill', (string) $data['house_ads_fill']);
        HouseBanner::flush();

        return back()->with('status', 'บันทึกการหมุนแบนเนอร์แล้ว');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new HouseBanner);

        return back()->with('status', 'เพิ่มแบนเนอร์แล้ว');
    }

    public function update(Request $request, HouseBanner $banner): RedirectResponse
    {
        $this->save($request, $banner);

        return back()->with('status', 'บันทึกแบนเนอร์แล้ว');
    }

    public function toggle(HouseBanner $banner): RedirectResponse
    {
        $banner->update(['is_active' => ! $banner->is_active]);
        HouseBanner::flush();

        return back()->with('status', $banner->is_active ? 'เปิดแบนเนอร์แล้ว' : 'ปิดแบนเนอร์แล้ว');
    }

    public function destroy(HouseBanner $banner): RedirectResponse
    {
        $this->deleteFile($banner->image_path);
        $banner->delete();
        HouseBanner::flush();

        return back()->with('status', 'ลบแบนเนอร์แล้ว');
    }

    private function save(Request $request, HouseBanner $banner): void
    {
        // A creative is required only when CREATING and no image URL was supplied — an edit may keep
        // the one it already has.
        $fileRule = ($banner->exists || $request->filled('image_url')) ? 'nullable' : 'required';
        $endRules = ['nullable', 'date'];
        if ($request->filled('starts_at')) {
            $endRules[] = 'after_or_equal:starts_at';
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'slot' => ['required', 'in:all,'.implode(',', Ads::SLOTS)],
            'image_file' => [$fileRule, 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
            'weight' => ['nullable', 'integer', 'between:1,1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => $endRules,
            'sort' => ['nullable', 'integer', 'between:0,100000'],
        ], [
            'image_file.required' => 'กรุณาอัพโหลดรูปแบนเนอร์ หรือใส่ลิงก์รูป',
        ]);

        // Core fields first, so a NEW record has an id to name its uploaded file after.
        $banner->fill([
            'name' => $data['name'] ?? null,
            'slot' => $data['slot'],
            'link_url' => $data['link_url'] ?? null,
            'weight' => (int) ($data['weight'] ?? 10),
            'is_active' => $request->boolean('is_active'),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ])->save();

        if ($request->hasFile('image_file')) {
            $old = $banner->image_path;
            $basename = 'house'.$banner->id.'-'.bin2hex(random_bytes(3));
            $path = ImageStore::putWebp(
                (string) file_get_contents($request->file('image_file')->getRealPath()),
                'media/house-banners',
                $basename,
                1600,
            );
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

        HouseBanner::flush();
    }

    private function deleteFile(?string $path): void
    {
        if (! $path) {
            return;
        }
        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable $e) {
            // a leftover file is harmless; never fail the request over cleanup
        }
    }
}
