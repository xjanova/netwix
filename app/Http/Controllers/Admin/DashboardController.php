<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Genre;
use App\Models\Profile;
use App\Models\Rating;
use App\Models\UsdtOrder;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $usersTotal = User::count();
        $contentTotal = Content::count();

        // Revenue = money that actually arrived. Every paid route on the site (gold top-ups, Pro,
        // ad bookings) mints a UsdtOrder and AdvertiseController links each booking to one, so
        // summing paid orders is the whole picture with nothing double-counted.
        //
        // This card used to multiply a hardcoded ฿99/199/349 table by the number of users on each
        // plan — but `users.plan` DEFAULTS to 'premium', so every signup counted as a ฿349
        // subscriber and the dashboard reported revenue nobody had paid.
        $revenuePaid = (float) UsdtOrder::whereNotNull('paid_at')->sum('amount_usdt');
        $revenue30d = (float) UsdtOrder::whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subDays(30))->sum('amount_usdt');
        $payingUsers = UsdtOrder::whereNotNull('paid_at')->distinct()->count('user_id');

        // Pro split. `plan` can't be trusted (see above), so "paid" means the member has actually
        // paid for something; everyone else holding Pro is on a free/promo grant.
        $proTotal = User::where(fn ($q) => $q->whereIn('plan', ['standard', 'premium'])->orWhere('pro_until', '>', now()))->count();
        $proFree = max(0, $proTotal - $payingUsers);

        $stats = [
            ['label' => 'สมาชิกทั้งหมด', 'value' => number_format($usersTotal), 'delta' => '▲ สมาชิกใหม่ '.User::whereDate('created_at', today())->count().' วันนี้', 'positive' => true, 'glow' => '#ff2d55'],
            ['label' => 'โปรไฟล์ผู้ชม', 'value' => number_format(Profile::count()), 'delta' => 'เฉลี่ย '.($usersTotal ? round(Profile::count() / $usersTotal, 1) : 0).' /บัญชี', 'positive' => null, 'glow' => '#b026ff'],
            ['label' => 'คอนเทนต์', 'value' => number_format($contentTotal), 'delta' => '+'.Content::whereDate('created_at', '>=', now()->subWeek())->count().' เรื่องใหม่สัปดาห์นี้', 'positive' => null, 'glow' => '#ff2d55'],
            [
                'label' => 'รายได้จริง (รับแล้วทั้งหมด)',
                'value' => '$'.number_format($revenuePaid, 2),
                'delta' => $revenuePaid > 0
                    ? '$'.number_format($revenue30d, 2).' ใน 30 วัน · ผู้จ่าย '.number_format($payingUsers).' คน'
                    : 'ยังไม่มีออเดอร์ที่ชำระเข้ามา',
                'positive' => $revenuePaid > 0,
                'glow' => '#b026ff',
            ],
            [
                'label' => 'สมาชิก Pro',
                'value' => number_format($proTotal),
                'delta' => 'จ่ายเงินจริง '.number_format($payingUsers).' · แจกฟรี/โปรโมชัน '.number_format($proFree),
                'positive' => null,
                'glow' => '#f5c518',
            ],
        ];

        // Platform split of watches (web vs app), shown side by side so the owner sees where people
        // watch. Since `views` was rebased onto this pair, the two now add up to the grand total.
        $viewsWeb = (int) Content::sum('views_web');
        $viewsApp = (int) Content::sum('views_app');

        // Real member stars (1–5). The old tile averaged `contents.rating`, which was a random
        // number assigned at import — 23k titles, none outside the generator's 7.8–9.6 window.
        $ratingCount = Rating::count();
        $ratingAvg = $ratingCount ? round((float) Rating::avg('stars'), 1) : null;

        $miniMetrics = [
            // "Now" has to mean now: this used to count every unfinished progress row ever, so a
            // title someone abandoned months ago still read as a live viewer.
            ['label' => 'กำลังดูอยู่ (30 นาที)', 'value' => number_format(WatchProgress::where('last_watched_at', '>=', now()->subMinutes(30))->count())],
            ['label' => 'วิวจากเว็บ', 'value' => number_format($viewsWeb)],
            ['label' => 'วิวจากแอป', 'value' => number_format($viewsApp)],
            ['label' => 'สมัครใหม่วันนี้', 'value' => number_format(User::whereDate('created_at', today())->count())],
            ['label' => 'ตอนทั้งหมด', 'value' => number_format(DB::table('episodes')->count())],
            ['label' => 'ดูจบเฉลี่ย', 'value' => (WatchProgress::count() ? round(WatchProgress::avg('percent')) : 0).'%'],
            ['label' => 'ค้างดูไม่จบ', 'value' => number_format(WatchProgress::whereBetween('percent', [1, 94])->count())],
            ['label' => 'คะแนนจากสมาชิก', 'value' => $ratingAvg !== null ? $ratingAvg.' / 5' : '—', 'hint' => $ratingCount ? number_format($ratingCount).' รายการ' : 'ยังไม่มีใครให้คะแนน'],
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

        // Ranked by views earned ON NetWix. Import no longer seeds `views` from the source site and
        // the column has been rebased onto this sum, so the two now agree — but the explicit form
        // stays, because web+app is the counter import can never touch.
        $topContent = Content::orderByRaw('(views_web + views_app) DESC')
            ->orderByDesc('id')
            ->withCount('ratings')
            ->withAvg('ratings', 'stars')
            ->with('genres')
            ->take(5)
            ->get();

        $storage = \App\Support\MediaUsage::summary();

        // Whole-source outages found by netwix:source-canary — the loudest thing on the page, because
        // one dead source is thousands of un-playable titles and it must not wait to be stumbled upon.
        $sourcesDown = collect(\App\Support\SourceHealth::down())
            ->map(fn ($v, $id) => [
                'id' => $id,
                'name' => app(\App\Services\Import\SourceRegistry::class)->get($id)?->displayName() ?? $id,
                'since' => $v['down_since'] ? Carbon::parse($v['down_since'])->diffForHumans() : null,
                'titles' => Content::withoutGlobalScopes()->where('source', $id)->where('is_published', true)->count(),
            ])
            ->values();

        return view('admin.dashboard', compact('stats', 'miniMetrics', 'activity', 'typeBreakdown', 'genreShares', 'topContent', 'storage', 'sourcesDown'));
    }
}
