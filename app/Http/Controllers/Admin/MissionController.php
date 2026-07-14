<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Mission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MissionController extends Controller
{
    public function index(): View
    {
        return view('admin.missions.index', [
            'missions' => Mission::orderBy('sort')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->save($request, new Mission);

        return back()->with('status', 'เพิ่มภารกิจแล้ว');
    }

    public function update(Request $request, Mission $mission): RedirectResponse
    {
        $this->save($request, $mission);

        return back()->with('status', 'บันทึกภารกิจแล้ว');
    }

    public function destroy(Mission $mission): RedirectResponse
    {
        $mission->delete();

        return back()->with('status', 'ลบภารกิจแล้ว');
    }

    public function toggle(Mission $mission): RedirectResponse
    {
        $mission->update(['is_active' => ! $mission->is_active]);

        return back()->with('status', $mission->is_active ? 'เปิดภารกิจแล้ว' : 'ปิดภารกิจแล้ว');
    }

    private function save(Request $request, Mission $mission): void
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'video_source' => ['required', 'in:youtube,url'],
            'video_ref' => ['required', 'string', 'max:2048'],
            'poster' => ['nullable', 'string', 'max:2048'],
            'required_seconds' => ['required', 'integer', 'between:5,7200'],
            'reward_kind' => ['required', 'in:silver,gold'],
            'reward_amount' => ['required', 'integer', 'between:1,1000000'],
            'repeat' => ['required', 'in:once,daily'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'between:0,100000'],
        ]);

        // For a YouTube mission, accept a full URL or a bare id — store the normalised 11-char id.
        if ($data['video_source'] === 'youtube') {
            $data['video_ref'] = Content::youtubeIdFrom($data['video_ref']) ?: $data['video_ref'];
        }

        $mission->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'video_source' => $data['video_source'],
            'video_ref' => $data['video_ref'],
            'poster' => $data['poster'] ?? null,
            'required_seconds' => (int) $data['required_seconds'],
            'reward_kind' => $data['reward_kind'],
            'reward_amount' => (int) $data['reward_amount'],
            'repeat' => $data['repeat'],
            'is_active' => $request->boolean('is_active'),
            'sort' => (int) ($data['sort'] ?? 0),
        ])->save();
    }
}
