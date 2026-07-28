<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentMirror;
use App\Models\Setting;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * "หนังที่ใช้ลิ้งค์สำรอง" — every title with a failover link, of either kind:
 *  - a MIRROR chain ([App\Models\ContentMirror]): the same title on other sites, paired up because it
 *    was imported twice, which [App\Support\MirrorRotation] rotates through when a link won't open;
 *  - the older single backup_* link the netwix:find-backups bot sources (see [App\Support\BackupFinder]).
 * Shows each title's chain in the order it will actually be tried, and lets the admin turn the daily
 * finder on/off.
 */
class BackupController extends Controller
{
    public function __construct(private SourceRegistry $registry) {}

    public function index(): View
    {
        $items = Content::query()
            ->where(fn ($q) => $q
                ->whereHas('mirrors')
                ->orWhereHas('episodes', fn ($e) => $e->whereNotNull('backup_source')))
            ->with([
                'mirrors' => fn ($q) => $q->inChainOrder(),
                'episodes' => fn ($q) => $q->whereNotNull('backup_source'),
            ])
            ->orderByDesc('updated_at')
            ->paginate(30);

        // Per title: the chain of site labels after the title's own source, whether an admin forced a
        // link, and how many of its mirrors are currently failing.
        $chains = [];
        $forced = [];
        $ailing = [];
        foreach ($items as $c) {
            $chain = $c->mirrors->map(fn ($m) => $this->label($m->source))->all();

            $ep = $c->episodes->first();
            if ($ep?->backup_source && ! $c->mirrors->contains('source', $ep->backup_source)) {
                $chain[] = $this->label($ep->backup_source);
            }

            $chains[$c->id] = $chain;
            $forced[$c->id] = (bool) $ep?->backup_forced;
            $ailing[$c->id] = $c->mirrors->where('fail_streak', '>', 0)->count();
        }

        return view('admin.backups.index', [
            'items' => $items,
            'chains' => $chains,
            'forced' => $forced,
            'ailing' => $ailing,
            'mirrorCount' => ContentMirror::count(),
            'enabled' => Setting::flag('backup_finder_enabled', false),
            'poolNames' => collect($this->registry->backupPool())->map(fn ($s) => $s->displayName())->values()->all(),
        ]);
    }

    private function label(string $sourceId): string
    {
        return $this->registry->get($sourceId)?->displayName() ?? $sourceId;
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
