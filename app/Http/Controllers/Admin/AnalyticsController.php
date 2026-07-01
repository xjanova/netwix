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
        // Signups per day, last 14 days.
        $signups = collect(range(13, 0))->map(function ($n) {
            $date = Carbon::today()->subDays($n);

            return [
                'label' => $date->format('j/n'),
                'value' => User::whereDate('created_at', $date)->count(),
            ];
        });
        $signupMax = max(1, $signups->max('value'));
        $signups = $signups->map(fn ($d) => $d + ['height' => round(($d['value'] / $signupMax) * 100).'%']);

        // Views by content type.
        $typeViews = collect(['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'ซีรีส์แนวตั้ง'])
            ->map(fn ($label, $type) => [
                'label' => $label,
                'value' => (int) Content::where('type', $type)->sum('views'),
            ])->values();
        $typeTotal = max(1, $typeViews->sum('value'));
        $typeViews = $typeViews->map(fn ($d) => $d + ['pct' => round(($d['value'] / $typeTotal) * 100).'%']);

        // Genre popularity by total views.
        $genrePopularity = Genre::with('contents')->get()
            ->map(fn ($g) => ['label' => $g->name, 'value' => (int) $g->contents->sum('views')])
            ->sortByDesc('value')->take(8)->values();
        $genreMax = max(1, $genrePopularity->max('value') ?: 1);
        $genrePopularity = $genrePopularity->map(fn ($d) => $d + ['pct' => round(($d['value'] / $genreMax) * 100).'%']);

        $kpis = [
            ['label' => 'ยอดวิวรวม', 'value' => number_format((int) Content::sum('views'))],
            ['label' => 'อัตราดูจบเฉลี่ย', 'value' => (WatchProgress::count() ? round(WatchProgress::avg('percent')) : 0).'%'],
            ['label' => 'สมาชิกใหม่ (30 วัน)', 'value' => number_format(User::where('created_at', '>=', now()->subDays(30))->count())],
        ];

        return view('admin.analytics', compact('signups', 'typeViews', 'genrePopularity', 'kpis'));
    }
}
