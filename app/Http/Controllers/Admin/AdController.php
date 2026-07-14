<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\Genre;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Admin CRUD for pre-roll ad campaigns (partials/preroll-ad renders them). Mirrors MissionController's
 * shape; adds image/video file upload (image → WebP via ImageStore, video → public disk) alongside a
 * plain URL / YouTube-link creative.
 */
class AdController extends Controller
{
    /** Content types an ad can target (anime is a `series` + genre, so genre targeting covers it). */
    public const TYPES = ['movie' => 'ภาพยนตร์', 'series' => 'ซีรีส์', 'vertical' => 'ซีรีส์แนวตั้ง'];

    public function index(): View
    {
        return view('admin.ads.index', [
            'ads' => AdCampaign::with('genre')->orderByDesc('sort')->orderByDesc('id')->get(),
            'genres' => Genre::orderBy('name')->get(),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new AdCampaign);

        return back()->with('status', 'เพิ่มโฆษณาแล้ว');
    }

    public function update(Request $request, AdCampaign $ad): RedirectResponse
    {
        $this->save($request, $ad);

        return back()->with('status', 'บันทึกโฆษณาแล้ว');
    }

    public function toggle(AdCampaign $ad): RedirectResponse
    {
        $ad->update(['is_active' => ! $ad->is_active]);

        return back()->with('status', $ad->is_active ? 'เปิดโฆษณาแล้ว' : 'ปิดโฆษณาแล้ว');
    }

    public function destroy(AdCampaign $ad): RedirectResponse
    {
        if ($ad->media_path) {
            try {
                Storage::disk('public')->delete($ad->media_path);
            } catch (Throwable $e) {
                // best-effort file cleanup
            }
        }
        $ad->delete();

        return back()->with('status', 'ลบโฆษณาแล้ว');
    }

    private function save(Request $request, AdCampaign $ad): void
    {
        $isVideo = $request->input('media_type') === 'video';
        // Require a creative only when CREATING and no URL was given (an edit may keep its existing one).
        $mediaFileRule = ($ad->exists || $request->filled('media_url')) ? 'nullable' : 'required';
        // Only enforce end >= start when a start is actually given (an "end-only" window is valid).
        $endRules = ['nullable', 'date'];
        if ($request->filled('starts_at')) {
            $endRules[] = 'after_or_equal:starts_at';
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'media_type' => ['required', 'in:image,video'],
            'media_file' => [$mediaFileRule, 'file', $isVideo ? 'max:51200' : 'max:8192',
                $isVideo ? 'mimes:mp4,webm,ogg,mov,m4v' : 'mimes:jpg,jpeg,png,webp,gif'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'caption' => ['nullable', 'string', 'max:500'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'skip_after' => ['required', 'integer', 'between:0,120'],
            'image_seconds' => ['required', 'integer', 'between:3,120'],
            'target' => ['required', 'in:all,type,genre'],
            'target_type' => ['nullable', 'required_if:target,type', 'in:movie,series,vertical'],
            'target_genre_id' => ['nullable', 'required_if:target,genre', 'integer', 'exists:genres,id'],
            'frequency' => ['required', 'in:always,session,daily'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => $endRules,
            'sort' => ['nullable', 'integer', 'between:0,100000'],
        ], [
            'media_file.required' => 'กรุณาอัพโหลดไฟล์ หรือใส่ลิงก์วิดีโอ/รูปภาพ',
        ]);

        // Core (non-media) fields first, so a NEW record has an id to name its uploaded file after.
        $ad->fill([
            'name' => $data['name'],
            'media_type' => $data['media_type'],
            'caption' => $data['caption'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'skippable' => $request->boolean('skippable'),
            'skip_after' => (int) $data['skip_after'],
            'image_seconds' => (int) $data['image_seconds'],
            'target' => $data['target'],
            'target_type' => $data['target'] === 'type' ? $data['target_type'] : null,
            'target_genre_id' => $data['target'] === 'genre' ? $data['target_genre_id'] : null,
            'frequency' => $data['frequency'],
            'hide_for_pro' => $request->boolean('hide_for_pro'),
            'is_active' => $request->boolean('is_active'),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'sort' => (int) ($data['sort'] ?? 0),
        ])->save();

        // Media: a fresh upload wins; else a provided URL; else keep whatever is already stored.
        if ($request->hasFile('media_file')) {
            $this->attachUpload($request->file('media_file'), $ad, $data['media_type']);
        } elseif (filled($data['media_url'] ?? null)) {
            $old = $ad->media_path;
            $ad->forceFill(['media_url' => $data['media_url'], 'media_path' => null])->save();
            $this->deleteFile($old);
        }
    }

    private function attachUpload($file, AdCampaign $ad, string $type): void
    {
        $old = $ad->media_path;
        $basename = 'ad'.$ad->id.'-'.bin2hex(random_bytes(3));

        if ($type === 'image') {
            $path = ImageStore::putWebp((string) file_get_contents($file->getRealPath()), 'media/ads', $basename, 1600);
        } else {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
            $path = $file->storeAs('media/ads', $basename.'.'.$ext, 'public') ?: null;
        }

        if ($path) {
            $ad->forceFill(['media_path' => $path, 'media_url' => null])->save();
            if ($old && $old !== $path) {
                $this->deleteFile($old);
            }
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
            // best-effort
        }
    }
}
