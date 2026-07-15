<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Genre;
use App\Models\Profile;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Monthly price per plan (THB). */
    private const PLAN_PRICE = ['basic' => 99, 'standard' => 199, 'premium' => 349];

    public function index(): View
    {
        $usersTotal = User::count();
        $contentTotal = Content::count();

        $revenue = collect(self::PLAN_PRICE)
            ->map(fn ($price, $plan) => $price * User::where('plan', $plan)->count())
            ->sum();

        $stats = [
            ['label' => 'สมาชิกทั้งหมด', 'value' => number_format($usersTotal), 'delta' => '▲ สมาชิกใหม่ '.User::whereDate('created_at', today())->count().' วันนี้', 'positive' => true, 'glow' => '#ff2d55'],
            ['label' => 'โปรไฟล์ผู้ชม', 'value' => number_format(Profile::count()), 'delta' => 'เฉลี่ย '.($usersTotal ? round(Profile::count() / $usersTotal, 1) : 0).' /บัญชี', 'positive' => null, 'glow' => '#b026ff'],
            ['label' => 'คอนเทนต์', 'value' => number_format($contentTotal), 'delta' => '+'.Content::whereDate('created_at', '>=', now()->subWeek())->count().' เรื่องใหม่สัปดาห์นี้', 'positive' => null, 'glow' => '#ff2d55'],
            ['label' => 'รายได้ (ประมาณ/เดือน)', 'value' => '฿'.number_format($revenue), 'delta' => 'จากแพ็กเกจสมาชิก', 'positive' => true, 'glow' => '#b026ff'],
        ];

        // Platform split of watches (web vs app). views_web/views_app accumulate from the day the
        // split shipped, so together they're ≤ the all-time `views` grand total (older views are
        // un-attributed) — the dashboard shows them side by side so the owner sees where people watch.
        $viewsWeb = (int) Content::sum('views_web');
        $viewsApp = (int) Content::sum('views_app');

        $miniMetrics = [
            ['label' => 'กำลังดูอยู่', 'value' => number_format(WatchProgress::whereBetween('percent', [1, 94])->count())],
            ['label' => 'วิวจากเว็บ', 'value' => number_format($viewsWeb)],
            ['label' => 'วิวจากแอป', 'value' => number_format($viewsApp)],
            ['label' => 'สมัครใหม่วันนี้', 'value' => number_format(User::whereDate('created_at', today())->count())],
            ['label' => 'ตอนทั้งหมด', 'value' => number_format(DB::table('episodes')->count())],
            ['label' => 'ดูจบเฉลี่ย', 'value' => (WatchProgress::count() ? round(WatchProgress::avg('percent')) : 0).'%'],
            ['label' => 'NetWix Originals', 'value' => number_format(Content::where('is_original', true)->count())],
            ['label' => 'คะแนนเฉลี่ย', 'value' => number_format((float) (Content::avg('rating') ?? 0), 1).' / 10'],
        ];

        // 14-day watch activity → SVG area chart
        $activity = collect(range(13, 0))->map(function ($n) {
            $date = Carbon::today()->subDays($n);

            return ['label' => $date->format('j/n'), 'value' => WatchProgress::whereDate('last_watched_at', $date)->count()];
        })->values();

        // Content mix by type (for the donut)
        $typeBreakdown = collect([
            'series' => ['label' => 'ซีรี่ส์', 'color' => '#b026ff'],
            'movie' => ['label' => 'ภาพยนตร์', 'color' => '#ff2d55'],
            'vertical' => ['label' => 'แนวตั้ง', 'color' => '#3ecf8e'],
        ])->map(fn ($m, $type) => $m + ['value' => Content::where('type', $type)->count()])->values();

        // Genre shares
        $genreCounts = Genre::withCount('contents')->orderByDesc('contents_count')->take(5)->get();
        $genreTotal = max(1, $genreCounts->sum('contents_count'));
        $genreShares = $genreCounts->map(fn ($g) => [
            'label' => $g->name,
            'pct' => round(($g->contents_count / $genreTotal) * 100).'%',
        ]);

        $topContent = Content::orderByDesc('views')->with('genres')->take(5)->get();

        $storage = \App\Support\MediaUsage::summary();

        return view('admin.dashboard', compact('stats', 'miniMetrics', 'activity', 'typeBreakdown', 'genreShares', 'topContent', 'storage'));
    }
}
