<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Content;
use App\Models\Setting;
use App\Services\AppRelease;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(AppRelease $appRelease): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('profiles.index');
        }

        // Real catalog data powers the logged-out landing (Netflix-style Top 10).
        // Adult (18+/20+) titles are kept off the public landing — they're for signed-in adults only.
        $notAdult = fn ($q) => $q->whereNotIn('maturity', \App\Support\Maturity::ADULT);

        $trending = Content::published()
            ->where($notAdult)
            ->rankedByEngagement()
            ->with('genres')
            ->take(10)
            ->get();

        // A shuffled wall of covers for the auto-scrolling hero marquee.
        // Posters with real artwork come first; the rest fall back to the
        // deterministic gradient placeholder in the view.
        $marquee = Content::published()
            ->where($notAdult)
            ->orderByRaw('CASE WHEN poster_path IS NULL THEN 1 ELSE 0 END')
            ->inRandomOrder()
            ->take(24)
            ->get(['id', 'title', 'slug', 'type', 'poster_path', 'is_original']);

        // Editable news ticker; fall back to sensible defaults when empty so the
        // hero never renders blank on a fresh install.
        $announcements = Announcement::active()->get();
        if ($announcements->isEmpty()) {
            $announcements = collect([
                (object) ['badge' => 'ใหม่', 'body' => 'ซีรีส์แนวตั้งดูจบไว ปัดขึ้น–ลงเหมือนโซเชียล', 'link' => null],
                (object) ['badge' => 'ยอดนิยม', 'body' => 'รวมหนังและซีรีส์ใหม่อัปเดตทุกสัปดาห์ ดูได้ไม่จำกัด', 'link' => null],
                (object) ['badge' => 'แอป', 'body' => 'ดาวน์โหลดแอป NetWix สำหรับ Android ได้แล้ววันนี้ • iOS เร็ว ๆ นี้', 'link' => null],
            ]);
        }

        // Social-proof stats for the landing (the view animates a count-up + gentle live tick).
        // Titles + views are the REAL DB aggregates. MEMBERS is a deliberately fabricated marketing
        // figure (per owner request) — an owner-set baseline that grows a fixed amount every day,
        // NOT App\Models\User::count(). Cached briefly so a burst of guests doesn't re-run it.
        $stats = \Illuminate\Support\Facades\Cache::remember('landing:stats', now()->addMinutes(5), function () {
            // Anchored 2026-07-04 → 4,137 that day, +213/day thereafter (i.e. 4,000+ and 200+/day).
            // Real signups aren't reflected here; the admin dashboard keeps the true User::count().
            $daysLive = max(0, (int) Carbon::parse('2026-07-04')->startOfDay()->diffInDays(now()->startOfDay()));

            return [
                'titles' => Content::published()->count(),
                'members' => 4137 + 213 * $daysLive,
                'views' => (int) Content::sum('views'),
            ];
        });

        // Whether the Android app is live (drives the "พร้อมโหลดแล้ววันนี้" state + version pill).
        // latest() hits the GitHub API on a cold cache — never let a network blip 500 the homepage.
        try {
            $app = $appRelease->latest();
        } catch (\Throwable $e) {
            $app = null;
        }

        return view('frontend.landing', [
            'trending' => $trending,
            'marquee' => $marquee,
            'announcements' => $announcements,
            'emailReg' => Setting::flag('email_registration_enabled', true),
            'stats' => $stats,
            'app' => $app,
        ]);
    }
}
