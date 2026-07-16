<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppDevice;
use Illuminate\View\View;

/**
 * Read-only device statistics ("สถิติแอป") — the analytics view over the rows
 * TelemetryController collects. Written by the app on every launch.
 */
class AppStatsController extends Controller
{
    public function index(): View
    {
        $breakdown = fn (string $col) => AppDevice::query()
            ->selectRaw("coalesce(nullif($col, ''), 'ไม่ระบุ') as k, count(*) as c")
            ->groupBy('k')->orderByDesc('c')->limit(12)->get();

        return view('admin.app-stats.index', [
            'total' => AppDevice::count(),
            'active7' => AppDevice::where('last_seen_at', '>=', now()->subDays(7))->count(),
            'active30' => AppDevice::where('last_seen_at', '>=', now()->subDays(30))->count(),
            'linked' => AppDevice::whereNotNull('user_id')->count(),
            'byPlatform' => $breakdown('platform'),
            'byVersion' => $breakdown('app_version'),
            'byModel' => $breakdown('device_model'),
            'byOs' => $breakdown('os_version'),
            'recent' => AppDevice::orderByDesc('last_seen_at')->limit(30)->get(),
        ]);
    }
}
