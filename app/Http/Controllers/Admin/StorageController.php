<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use App\Models\MirrorJob;
use App\Models\Setting;
use App\Services\MediaMirror;
use App\Support\ImageStore;
use App\Support\MediaUsage;
use App\Support\MirrorPlan;
use App\Support\MirrorProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StorageController extends Controller
{
    /** Master switch. Off means the worker refuses to download no matter what the job rows say. */
    public const SWITCH_KEY = 'mirror_enabled';

    public function index(): View
    {
        $summary = MediaUsage::summary();
        $rows = MirrorPlan::rows();

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
            'rows' => $rows,
            'totals' => MirrorPlan::totals($rows),
            'switchOn' => Setting::flag(self::SWITCH_KEY, false),
            'usdPerTb' => MirrorPlan::usdPerTbMonth(),
            'storageDisk' => MediaMirror::disk(),
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

    /**
     * Live numbers for the monitor, polled every few seconds while the page is open.
     *
     * Returns exactly what the page renders, so there is one shape of truth and no second code path
     * that could drift from the first render.
     */
    public function monitor(): JsonResponse
    {
        $rows = MirrorPlan::rows();

        return response()->json([
            'rows' => $rows,
            'totals' => MirrorPlan::totals($rows),
            'switch' => Setting::flag(self::SWITCH_KEY, false),
            'at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * Master switch for the whole downloader.
     *
     * Nothing downloads while this is off — the worker checks it first and exits. It is separate from
     * the per-source start buttons on purpose: the admin can lay out a plan (which sources, how many
     * episodes) and review it before anything touches the network.
     */
    public function toggleSwitch(Request $request): JsonResponse
    {
        $on = $request->boolean('on');
        Setting::write(self::SWITCH_KEY, $on ? '1' : '0');

        if (! $on) {
            // Turning the master switch off must also stand the sources down, otherwise flipping it
            // back on would silently resume every run that was in flight days earlier.
            MirrorJob::whereIn('status', [MirrorJob::STATUS_QUEUED, MirrorJob::STATUS_RUNNING])
                ->update(['status' => MirrorJob::STATUS_PAUSED]);
        }

        return response()->json(['ok' => true, 'on' => $on]);
    }

    /**
     * Measure real episode sizes for one source (see [App\Support\MirrorProbe]).
     *
     * Runs inline rather than on the queue: it is a handful of ranged requests, and the admin is
     * standing in front of the page waiting for the number.
     */
    public function probe(Request $request, MirrorProbe $probe): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32'],
            'count' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        @set_time_limit(180);
        $result = $probe->run($data['source'], (int) ($data['count'] ?? 3));

        return response()->json([
            'ok' => $result['ok'] > 0,
            'measured' => $result['ok'],
            'failed' => $result['fail'],
            'avg' => $result['avg'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Start / pause / resume / stop / reset one source.
     *
     * `start` only ever sets `queued`; it is [App\Console\Commands\MirrorRun] that moves a row to
     * `running`. That distinction is the whole point of the heartbeat on the page — "queued but no
     * worker" is a real state the admin needs to be able to see, not a spinner that hangs.
     */
    public function control(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:32'],
            'action' => ['required', 'in:start,pause,resume,stop,reset'],
            'scope' => ['nullable', 'in:missing,all'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200000'],
        ]);

        $job = MirrorJob::firstOrNew(['source' => $data['source']]);

        if (in_array($data['action'], ['start', 'resume'], true)) {
            $source = app(\App\Services\Import\SourceRegistry::class)->get($data['source']);
            if (! $source?->isProgressive()) {
                return response()->json([
                    'ok' => false,
                    'error' => 'แหล่งนี้เป็นสตรีม HLS — ตัวดาวน์โหลดยังรวมไฟล์ไม่ได้ (ต้องต่อ ffmpeg ก่อน)',
                ], 422);
            }
            if (! Setting::flag(self::SWITCH_KEY, false)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'สวิตช์ระบบดูดยังปิดอยู่ — เปิดสวิตช์ด้านบนก่อน',
                ], 422);
            }
        }

        match ($data['action']) {
            'start' => $job->fill([
                'status' => MirrorJob::STATUS_QUEUED,
                'scope' => $data['scope'] ?? 'missing',
                'episode_limit' => $data['limit'] ?? null,
                'done_count' => 0,
                'fail_count' => 0,
                'bytes_done' => 0,
                'last_error' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]),
            'resume' => $job->fill(['status' => MirrorJob::STATUS_QUEUED, 'finished_at' => null]),
            'pause' => $job->fill(['status' => MirrorJob::STATUS_PAUSED]),
            'stop' => $job->fill(['status' => MirrorJob::STATUS_IDLE, 'finished_at' => now()]),
            'reset' => $job->fill([
                'status' => MirrorJob::STATUS_IDLE,
                'done_count' => 0, 'fail_count' => 0, 'bytes_done' => 0,
                'last_error' => null, 'last_title' => null, 'started_at' => null, 'finished_at' => null,
            ]),
        };

        $job->save();

        return response()->json(['ok' => true, 'status' => $job->status, 'state' => $job->displayState()]);
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

    /** Admin: set an episode's cover to a JPEG frame the admin grabbed in the picker (overwrites). */
    public function setThumb(Request $request, Episode $episode): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:10000000']]);   // uploads up to ~7MB
        $bin = ImageStore::decodeDataUrl($data['image']);
        if ($bin === null) {
            return response()->json(['ok' => false, 'error' => 'invalid'], 422);
        }
        // Unique filename per save (see ImageStore::putCover) — a fresh PATH is the only reliable way
        // to make Cloudflare/the browser show the new cover immediately; a same-name "?t=" overwrite
        // kept serving the stale image. The old file is cleaned up.
        $path = ImageStore::putCover($bin, 'media/thumbs', (string) $episode->id, $episode->thumbnail_path, 720);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'decode'], 422);
        }
        $episode->update(['thumbnail_path' => $path]);

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($path)]);
    }

    /** Admin: set a title's poster (2:3) or backdrop (16:9) from a JPEG frame grabbed in the picker. */
    public function setPoster(Request $request, Content $content): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'string', 'max:10000000'],   // uploads up to ~7MB
            'kind' => ['required', 'in:poster,backdrop'],
        ]);

        $bin = ImageStore::decodeDataUrl($data['image']);
        if ($bin === null) {
            return response()->json(['ok' => false, 'error' => 'invalid'], 422);
        }
        $max = $data['kind'] === 'poster' ? 1000 : 1600;
        $col = $data['kind'] === 'poster' ? 'poster_path' : 'backdrop_path';
        $path = ImageStore::putCover($bin, 'media/posters', "{$content->id}-{$data['kind']}", $content->{$col}, $max);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'decode'], 422);
        }
        $content->update([$col => $path]);

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($path)]);
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
