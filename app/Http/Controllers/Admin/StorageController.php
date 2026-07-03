<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use App\Services\MediaMirror;
use App\Support\MediaUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(): View
    {
        $summary = MediaUsage::summary();

        // Per-title breakdown for titles that have any mirrored episode.
        $titles = Content::query()
            ->whereHas('episodes', fn ($q) => $q->whereNotNull('mirrored_at'))
            ->withCount([
                'episodes as mirrored_count' => fn ($q) => $q->whereNotNull('mirrored_at'),
                'episodes as total_episodes',
            ])
            ->withSum(['episodes as media_bytes' => fn ($q) => $q->whereNotNull('mirrored_at')], 'file_size')
            ->orderByDesc('media_bytes')
            ->paginate(20);

        // Planning projection: if every imported (mirrorable) episode were mirrored at the current
        // average size, how big would it get?
        $mirrorableTotal = Episode::whereNotNull('source')
            ->whereHas('content', fn ($q) => $q->where('source', 'rongyok'))
            ->count();
        $projectedBytes = $summary['avg'] * $mirrorableTotal;

        $rongyok = fn ($q) => $q->where('source', 'rongyok');

        return view('admin.storage.index', [
            'summary' => $summary,
            'titles' => $titles,
            'mirrorableTotal' => $mirrorableTotal,
            'projectedBytes' => $projectedBytes,
            'agent' => \App\Support\IngestAgent::status(),
            'pendingCount' => Episode::whereNull('mirrored_at')->whereNotNull('source')
                ->where('mirror_attempts', '<', Episode::MIRROR_MAX_ATTEMPTS)
                ->whereHas('content', $rongyok)->count(),
            'unavailableCount' => Episode::whereNull('mirrored_at')
                ->where('mirror_attempts', '>=', Episode::MIRROR_MAX_ATTEMPTS)
                ->whereHas('content', $rongyok)->count(),
        ]);
    }

    /** Admin: download one episode onto our server (so it plays from our copy, no live link). */
    public function mirror(Episode $episode, MediaMirror $mirror): RedirectResponse
    {
        @set_time_limit(0);
        $r = $mirror->store($episode);

        return $r['ok']
            ? back()->with('status', "โหลดเก็บตอนที่ {$episode->number} แล้ว (".number_format(($r['bytes'] ?? 0) / 1e6, 1)." MB) — เล่นจากไฟล์ในเซิร์ฟเวอร์")
            : back()->withErrors(['mirror' => "โหลดตอนที่ {$episode->number} ไม่สำเร็จ: ".$r['error']]);
    }

    /** Admin: delete a stored file — the episode reverts to on-demand streaming. */
    public function unmirror(Episode $episode, MediaMirror $mirror): RedirectResponse
    {
        $mirror->delete($episode);

        return back()->with('status', "ลบไฟล์ตอนที่ {$episode->number} แล้ว — กลับไปสตรีมสดตามเดิม");
    }

    /** Admin: download every not-yet-stored episode of a title (progressive sources only). */
    public function mirrorContent(Content $content, MediaMirror $mirror): RedirectResponse
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $ok = 0;
        $fail = 0;
        foreach ($content->episodes()->whereNull('mirrored_at')->whereNotNull('source_ref')->orderBy('number')->get() as $ep) {
            if ($mirror->store($ep)['ok']) {
                $ok++;
            } else {
                $fail++;
            }
        }

        return back()->with('status', "โหลดเก็บ \"{$content->title}\" แล้ว {$ok} ตอน".($fail ? " · ไม่สำเร็จ {$fail} ตอน (ลิงก์อาจหมุน/ไม่พร้อม)" : ''));
    }
}
