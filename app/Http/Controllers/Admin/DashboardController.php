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
            ->sum(fn ($price, $plan) => $price * User::where('plan', $plan)->count());

        $stats = [
            ['label' => 'สมาชิกทั้งหมด', 'value' => number_format($usersTotal), 'delta' => '▲ สมาชิกใหม่ '.User::whereDate('created_at', today())->count().' วันนี้', 'positive' => true, 'glow' => '#ff2d55'],
            ['label' => 'โปรไฟล์ผู้ชม', 'value' => number_format(Profile::count()), 'delta' => 'เฉลี่ย '.($usersTotal ? round(Profile::count() / $usersTotal, 1) : 0).' /บัญชี', 'positive' => null, 'glow' => '#b026ff'],
            ['label' => 'คอนเทนต์', 'value' => number_format($contentTotal), 'delta' => '+'.Content::whereDate('created_at', '>=', now()->subWeek())->count().' เรื่องใหม่สัปดาห์นี้', 'positive' => null, 'glow' => '#ff2d55'],
            ['label' => 'รายได้ (ประมาณ/เดือน)', 'value' => '฿'.number_format($revenue), 'delta' => 'จากแพ็กเกจสมาชิก', 'positive' => true, 'glow' => '#b026ff'],
        ];

        $miniMetrics = [
            ['label' => 'กำลังดูอยู่', 'value' => number_format(WatchProgress::whereBetween('percent', [1, 94])->count())],
            ['label' => 'สมัครใหม่วันนี้', 'value' => number_format(User::whereDate('created_at', today())->count())],
            ['label' => 'ตอนทั้งหมด', 'value' => number_format(DB::table('episodes')->count())],
            ['label' => 'ดูจบเฉลี่ย', 'value' => (WatchProgress::count() ? round(WatchProgress::avg('percent')) : 0).'%'],
            ['label' => 'NetWix Originals', 'value' => number_format(Content::where('is_original', true)->count())],
            ['label' => 'คะแนนเฉลี่ย', 'value' => number_format((float) (Content::avg('rating') ?? 0), 1).' / 10'],
        ];

        // 7-day activity (watch-progress touches per day) → bar chart
        $days = collect(range(6, 0))->map(function ($n) {
            $date = Carbon::today()->subDays($n);
            $count = WatchProgress::whereDate('last_watched_at', $date)->count();

            return ['day' => ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'][$date->dayOfWeek], 'value' => $count];
        });
        $maxDay = max(1, $days->max('value'));
        $chartBars = $days->map(fn ($d) => [
            'day' => $d['day'],
            'value' => $d['value'],
            'height' => round(($d['value'] / $maxDay) * 100).'%',
        ]);

        // Genre shares
        $genreCounts = Genre::withCount('contents')->orderByDesc('contents_count')->take(5)->get();
        $genreTotal = max(1, $genreCounts->sum('contents_count'));
        $genreShares = $genreCounts->map(fn ($g) => [
            'label' => $g->name,
            'pct' => round(($g->contents_count / $genreTotal) * 100).'%',
        ]);

        $topContent = Content::orderByDesc('views')->with('genres')->take(5)->get();

        return view('admin.dashboard', compact('stats', 'miniMetrics', 'chartBars', 'genreShares', 'topContent'));
    }
}
