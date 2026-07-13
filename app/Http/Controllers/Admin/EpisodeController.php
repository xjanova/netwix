<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EpisodeController extends Controller
{
    public function store(Request $request, Content $content): RedirectResponse
    {
        $data = $request->validate([
            'season_number' => ['nullable', 'integer', 'between:1,50'],
            'number' => ['required', 'integer', 'between:1,999'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'between:0,600'],
            'video_url' => ['nullable', 'string', 'max:2048'],
            'thumbnail_path' => ['nullable', 'string', 'max:2048'],
        ]);

        $seasonId = null;
        if ($content->type === 'series') {
            $season = $content->seasons()->firstOrCreate(
                ['number' => $data['season_number'] ?? 1],
                ['title' => 'ซีซั่น '.($data['season_number'] ?? 1)],
            );
            $seasonId = $season->id;
        }

        $content->episodes()->create([
            'season_id' => $seasonId,
            'number' => $data['number'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'thumbnail_path' => $data['thumbnail_path'] ?? null,
            'sort' => $data['number'],
        ]);

        return back()->with('status', 'เพิ่มตอนเรียบร้อยแล้ว');
    }

    public function destroy(Content $content, Episode $episode): RedirectResponse
    {
        abort_unless($episode->content_id === $content->id, 404);
        $episode->delete();

        return back()->with('status', 'ลบตอนแล้ว');
    }

    /** Per-episode playback markers (blank = inherit the content-level default). */
    public function setMarkers(Request $request, Content $content, Episode $episode): RedirectResponse
    {
        abort_unless($episode->content_id === $content->id, 404);

        $data = $request->validate([
            'intro_end_seconds' => ['nullable', 'integer', 'between:0,36000'],
            'outro_seconds' => ['nullable', 'integer', 'between:0,36000'],
        ]);

        $episode->update([
            'intro_end_seconds' => $data['intro_end_seconds'] ?? null,
            'outro_seconds' => $data['outro_seconds'] ?? null,
        ]);

        return back()->with('status', "บันทึกมาร์คเวลาตอนที่ {$episode->number} แล้ว");
    }
}
