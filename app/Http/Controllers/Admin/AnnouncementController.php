<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::orderBy('sort')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Announcement::create($this->validated($request));

        return back()->with('status', 'เพิ่มข่าวสารแล้ว');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));

        return back()->with('status', 'บันทึกข่าวสารแล้ว');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return back()->with('status', 'ลบข่าวสารแล้ว');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'badge' => ['nullable', 'string', 'max:40'],
            'body' => ['required', 'string', 'max:300'],
            'link' => ['nullable', 'url:http,https', 'max:255'],
            'sort' => ['nullable', 'integer', 'between:0,999'],
        ], [
            'body.required' => 'กรุณากรอกข้อความข่าวสาร',
            'link.url' => 'ลิงก์ต้องเป็น URL ที่ถูกต้อง',
        ]);

        return [
            'badge' => $data['badge'] ?? null,
            'body' => $data['body'],
            'link' => $data['link'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
