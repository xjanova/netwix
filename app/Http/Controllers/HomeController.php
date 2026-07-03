<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Content;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('profiles.index');
        }

        // Real catalog data powers the logged-out landing (Netflix-style Top 10).
        $trending = Content::published()
            ->rankedByEngagement()
            ->with('genres')
            ->take(10)
            ->get();

        // A shuffled wall of covers for the auto-scrolling hero marquee.
        // Posters with real artwork come first; the rest fall back to the
        // deterministic gradient placeholder in the view.
        $marquee = Content::published()
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
                (object) ['badge' => 'แอป', 'body' => 'ดาวน์โหลดแอป NetWix เร็ว ๆ นี้ ทั้ง Android และ iOS', 'link' => null],
            ]);
        }

        return view('frontend.landing', [
            'trending' => $trending,
            'marquee' => $marquee,
            'announcements' => $announcements,
            'emailReg' => Setting::flag('email_registration_enabled', true),
        ]);
    }
}
