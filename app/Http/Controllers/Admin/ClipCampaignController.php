<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClipCampaign;
use App\Models\ClipCampaignPost;
use App\Models\Genre;
use App\Models\Setting;
use App\Support\ClipCampaignRunner;
use App\Support\FacebookPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "แคมเปญคลิปอัตโนมัติ" — CRUD + controls for the Phase 3 clip auto-post campaigns.
 * A campaign auto-picks a title on its schedule, cuts a clip, and posts it to the NetWix
 * Facebook page. This controller only manages config + fires manual runs; the actual work
 * runs on the queue (see ClipCampaignRunner / netwix:clips:publish).
 */
class ClipCampaignController extends Controller
{
    public function index(FacebookPublisher $fb): View
    {
        $campaigns = ClipCampaign::withCount('posts')
            ->with('genre:id,name', 'content:id,title')
            ->orderByDesc('is_enabled')->orderBy('name')->get();

        // Recent slot runs across all campaigns — the "did it actually post?" log.
        $recentPosts = ClipCampaignPost::with('campaign:id,name', 'content:id,title')
            ->latest('id')->take(25)->get();

        return view('admin.clip-campaigns.index', [
            'campaigns' => $campaigns,
            'recentPosts' => $recentPosts,
            'killEnabled' => Setting::flag('clip_campaigns_enabled', false),
            'fbConnected' => $fb->enabled(),
            'fbPageName' => $fb->pageName(),
        ]);
    }

    public function create(): View
    {
        return view('admin.clip-campaigns.form', [
            'campaign' => new ClipCampaign([
                'is_enabled' => false, 'pick' => 'trending', 'include_adult' => false,
                'avoid_recent_days' => 14, 'duration' => 45, 'aspect' => '9:16',
                'start_mode' => 'middle', 'duration_max' => null,
                'full_episode' => false, 'episode_pick' => 'first',
                'targets' => 'reels,feed', 'days' => '', 'slots' => ['18:00'],
            ]),
            'genres' => $this->genres(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['name']);
        ClipCampaign::create($data);

        return redirect()->route('admin.clip-campaigns.index')
            ->with('status', 'สร้างแคมเปญ "'.$data['name'].'" แล้ว — เปิดสวิตช์เพื่อเริ่มโพสต์อัตโนมัติ');
    }

    public function edit(ClipCampaign $clipCampaign): View
    {
        return view('admin.clip-campaigns.form', [
            'campaign' => $clipCampaign,
            'genres' => $this->genres(),
        ]);
    }

    public function update(Request $request, ClipCampaign $clipCampaign): RedirectResponse
    {
        $clipCampaign->update($this->validated($request));   // slug is kept stable across edits

        return redirect()->route('admin.clip-campaigns.index')
            ->with('status', 'บันทึกแคมเปญ "'.$clipCampaign->name.'" แล้ว');
    }

    public function destroy(ClipCampaign $clipCampaign): RedirectResponse
    {
        $name = $clipCampaign->name;
        $clipCampaign->delete();   // clip_campaign_posts cascade; produced clips keep (campaign_id → null)

        return back()->with('status', 'ลบแคมเปญ "'.$name.'" แล้ว');
    }

    /** Enable/disable a single campaign. */
    public function toggle(ClipCampaign $clipCampaign): RedirectResponse
    {
        $clipCampaign->update(['is_enabled' => ! $clipCampaign->is_enabled]);

        return back()->with('status', $clipCampaign->is_enabled
            ? 'เปิดแคมเปญ "'.$clipCampaign->name.'" แล้ว'
            : 'ปิดแคมเปญ "'.$clipCampaign->name.'" แล้ว');
    }

    /** Global master switch — pauses every automatic run without touching each campaign. */
    public function kill(Request $request): RedirectResponse
    {
        $on = $request->boolean('enabled');
        Setting::write('clip_campaigns_enabled', $on ? '1' : '0');

        return back()->with('status', $on
            ? 'เปิดระบบแคมเปญคลิปอัตโนมัติแล้ว — แคมเปญที่เปิดไว้จะเริ่มโพสต์ตามเวลาที่ตั้ง'
            : 'ปิดระบบแคมเปญคลิปอัตโนมัติทั้งหมดแล้ว (แคมเปญยังอยู่ครบ)');
    }

    /** "โพสต์ทันที" — fire this campaign now, ignoring its schedule (a live test). */
    public function runNow(ClipCampaign $clipCampaign, ClipCampaignRunner $runner): RedirectResponse
    {
        $now = Carbon::now(ClipCampaign::TZ);   // slot + post_date in Thai time (see ClipCampaign::TZ)
        $post = $runner->fire($clipCampaign, $now->format('H:i'), $now->toDateString(), manual: true);

        return back()->with('status', match ($post?->status) {
            'cutting' => 'เริ่มตัดคลิปของ "'.$clipCampaign->name.'" แล้ว — ระบบจะโพสต์ให้อัตโนมัติเมื่อคลิปเสร็จ (ดูสถานะในตารางด้านล่าง)',
            'skipped' => 'ยังไม่มีหนังที่เข้าเงื่อนไขให้โพสต์ในตอนนี้ (ลองปรับตัวกรอง หรือลดจำนวนวันห้ามซ้ำ)',
            default => 'สั่งรันแล้ว — สถานะ: '.($post?->status ?? 'ไม่สำเร็จ'),
        });
    }

    // ---- internals ----------------------------------------------------------

    /** @return array<string, mixed> validated + normalized attributes for create/update */
    private function validated(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'content_type' => ['nullable', Rule::in(['movie', 'series', 'anime', 'vertical'])],
            'genre_id' => ['nullable', 'integer', 'exists:genres,id'],
            'source' => ['nullable', 'string', 'max:32'],
            'content_id' => ['nullable', 'integer', 'exists:contents,id'],
            'pick' => ['required', Rule::in(['trending', 'random', 'newest'])],
            'avoid_recent_days' => ['required', 'integer', 'min:0', 'max:365'],
            'duration' => ['required', 'integer', 'min:5', 'max:600'],
            'duration_max' => ['nullable', 'integer', 'min:5', 'max:600', 'gte:duration'],
            'start_mode' => ['required', Rule::in(['middle', 'random'])],
            'episode_pick' => ['required', Rule::in(['first', 'random', 'sequential'])],
            'aspect' => ['required', Rule::in(['9:16', '1:1', '16:9'])],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => [Rule::in(['reels', 'feed'])],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:0,6'],
            'slots' => ['required', 'array', 'min:1'],
            'slots.*' => ['string'],
        ]);

        $slots = collect($request->input('slots', []))
            ->map(fn ($s) => ClipCampaign::normalizeSlot((string) $s))->filter()->unique()->values()->all();

        // A row with zero valid slots would silently never post — reject it loudly instead.
        if (empty($slots)) {
            throw ValidationException::withMessages([
                'slots' => 'กรุณาใส่เวลาโพสต์อย่างน้อย 1 ช่วง (รูปแบบ ชั่วโมง:นาที)',
            ]);
        }

        $targets = collect($request->input('targets', []))->intersect(['reels', 'feed'])->values();
        $days = collect($request->input('days', []))
            ->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 0 && $d <= 6)->unique()->sort()->values();

        return [
            'name' => trim((string) $request->input('name')),
            'is_enabled' => $request->boolean('is_enabled'),
            'content_type' => $request->input('content_type') ?: null,
            'genre_id' => $request->input('genre_id') ?: null,
            'source' => $request->input('source') ?: null,
            'content_id' => $request->input('content_id') ?: null,
            'pick' => $request->input('pick'),
            'include_adult' => $request->boolean('include_adult'),
            'avoid_recent_days' => (int) $request->input('avoid_recent_days'),
            'duration' => (int) $request->input('duration'),
            'duration_max' => $request->filled('duration_max') ? (int) $request->input('duration_max') : null,
            'start_mode' => $request->input('start_mode', 'middle'),
            'full_episode' => $request->boolean('full_episode'),
            'episode_pick' => $request->input('episode_pick', 'first'),
            'aspect' => $request->input('aspect'),
            'targets' => $targets->isEmpty() ? 'feed' : $targets->implode(','),
            'days' => $days->implode(','),
            'slots' => $slots,
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'campaign';
        $slug = $base;
        $i = 2;
        while (ClipCampaign::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return \Illuminate\Support\Collection<int, Genre> */
    private function genres()
    {
        return Genre::orderBy('name')->get(['id', 'name']);
    }
}
