<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Genre;
use App\Models\User;
use App\Models\WatchProgress;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(): View
    {
        // Signups per day, last 14 days — one grouped query instead of 14 counts.
        $signupsByDay = User::where('created_at', '>=', Carbon::today()->subDays(13))
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $signups = collect(range(13, 0))->map(function ($n) use ($signupsByDay) {
            $date = Carbon::today()->subDays($n);

            return [
                'label' => $date->format('j/n'),
                'value' => (int) $signupsByDay->get($date->format('Y-m-d'), 0),
            ];
        });
        $signupMax = max(1, $signups->max('value'));
        $signups = $signups->map(fn ($d) => $d + ['height' => round(($d['value'] / $signupMax) * 100).'%']);

        // Views by content type.
        $viewSums = Content::selectRaw('type, SUM(views) as v')->groupBy('type')->pluck('v', 'type');
        $typeViews = collect(['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'])
            ->map(fn ($label, $type) => [
                'label' => $label,
                'value' => (int) $viewSums->get($type, 0),
            ])->values();
        $typeTotal = max(1, $typeViews->sum('value'));
        $typeViews = $typeViews->map(fn ($d) => $d + ['pct' => round(($d['value'] / $typeTotal) * 100).'%']);

        // Genre popularity by total views — summed in SQL; with('contents') hydrated all
        // 15k+ contents (× genre pivot duplication) and blew the 128M PHP limit.
        $genrePopularity = Genre::withSum('contents as total_views', 'views')
            ->orderByDesc('total_views')->limit(8)->get()
            ->map(fn ($g) => ['label' => $g->name, 'value' => (int) $g->total_views])->values();
        $genreMax = max(1, $genrePopularity->max('value') ?: 1);
        $genrePopularity = $genrePopularity->map(fn ($d) => $d + ['pct' => round(($d['value'] / $genreMax) * 100).'%']);

        $kpis = [
            ['label' => 'ยอดวิวรวม', 'value' => number_format((int) $viewSums->sum())],
            ['label' => 'อัตราดูจบเฉลี่ย', 'value' => (WatchProgress::count() ? round(WatchProgress::avg('percent')) : 0).'%'],
            ['label' => 'สมาชิกใหม่ (30 วัน)', 'value' => number_format(User::where('created_at', '>=', now()->subDays(30))->count())],
        ];

        return view('admin.analytics', compact('signups', 'typeViews', 'genrePopularity', 'kpis'));
    }
}
