<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppDownload;
use App\Services\AppRelease;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin "ยอดดาวน์โหลดแอป" — how many times the Android app was downloaded from the
 * website (see AppDownload::record). Charts are CSS bars, same as the SEO dashboard
 * (no JS lib → CSP-safe), and every figure is aggregated in SQL rather than hydrated.
 */
class DownloadController extends Controller
{
    public function index(AppRelease $release): View
    {
        $since = Carbon::today()->subDays(29);
        $all = AppDownload::query();

        $kpis = [
            ['label' => 'ดาวน์โหลดทั้งหมด (สะสม)', 'value' => number_format((clone $all)->count())],
            ['label' => 'วันนี้', 'value' => number_format((clone $all)->where('created_at', '>=', Carbon::today())->count())],
            ['label' => '7 วันล่าสุด', 'value' => number_format((clone $all)->where('created_at', '>=', Carbon::today()->subDays(6))->count())],
            ['label' => '30 วันล่าสุด', 'value' => number_format((clone $all)->where('created_at', '>=', $since)->count())],
        ];

        // ---- Daily chart (30 days) ----
        $byDay = (clone $all)->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');
        $daily = collect(range(29, 0))->map(function ($n) use ($byDay) {
            $d = Carbon::today()->subDays($n);

            return ['label' => $d->format('j/n'), 'value' => (int) $byDay->get($d->format('Y-m-d'), 0)];
        });
        $dayMax = max(1, $daily->max('value'));
        $daily = $daily->map(fn ($d) => $d + ['height' => round($d['value'] / $dayMax * 100).'%']);

        // ---- Per-version (all-time) ----
        $byVersion = (clone $all)->selectRaw('version, COUNT(*) as c, MAX(created_at) as last_at')
            ->groupBy('version')->orderByDesc('c')->get();
        $verMax = max(1, (int) ($byVersion->max('c') ?: 1));
        $versions = $byVersion->map(fn ($r) => [
            'label' => $r->version,
            'value' => (int) $r->c,
            'pct' => round($r->c / $verMax * 100).'%',
            'ago' => $r->last_at ? Carbon::parse($r->last_at)->diffForHumans() : '-',
        ])->values();

        // ---- Splits (all-time) ----
        $total = (clone $all)->count();
        $android = (clone $all)->where('platform', 'android')->count();
        $members = (clone $all)->where('is_member', true)->count();
        $splits = [
            'total' => $total,
            'android' => $android,
            'other' => $total - $android,
            'androidPct' => $total ? round($android / $total * 100) : 0,
            'members' => $members,
            'guests' => $total - $members,
            'memberPct' => $total ? round($members / $total * 100) : 0,
        ];

        $latest = $release->latest();

        return view('admin.downloads.index', compact('kpis', 'daily', 'versions', 'splits', 'latest'));
    }
}
