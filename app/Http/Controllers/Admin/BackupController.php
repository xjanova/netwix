<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Setting;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * "หนังที่ใช้ลิ้งค์สำรอง" — titles currently running on a backup stream that the netwix:find-backups
 * bot sourced from another Halim site (see [App\Support\BackupFinder]). Shows each title with the
 * backup site's display name, and lets the admin turn the daily finder on/off.
 */
class BackupController extends Controller
{
    public function __construct(private SourceRegistry $registry) {}

    public function index(): View
    {
        $items = Content::query()
            ->whereHas('episodes', fn ($q) => $q->whereNotNull('backup_source'))
            ->with(['episodes' => fn ($q) => $q->whereNotNull('backup_source')])
            ->orderByDesc('updated_at')
            ->paginate(30);

        // content id → backup site display name (from the first backed-up episode's source).
        $siteLabels = [];
        foreach ($items as $c) {
            $sid = optional($c->episodes->first())->backup_source;
            $siteLabels[$c->id] = $sid ? ($this->registry->get($sid)?->displayName() ?? $sid) : '—';
        }

        return view('admin.backups.index', [
            'items' => $items,
            'siteLabels' => $siteLabels,
            'enabled' => Setting::flag('backup_finder_enabled', false),
            'poolNames' => collect($this->registry->backupPool())->map(fn ($s) => $s->displayName())->values()->all(),
        ]);
    }

    /** Turn the daily backup-link finder (netwix:find-backups) on/off. */
    public function toggle(\Illuminate\Http\Request $request): RedirectResponse
    {
        $on = $request->boolean('enabled');
        Setting::write('backup_finder_enabled', $on ? '1' : '0');

        return back()->with('status', $on
            ? 'เปิดค้นหาลิ้งค์สำรองอัตโนมัติแล้ว — ระบบจะหาลิ้งค์สำรองให้หนังที่เล่นไม่ได้ทุกวัน'
            : 'ปิดการค้นหาลิ้งค์สำรองอัตโนมัติแล้ว');
    }
}
