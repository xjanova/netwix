<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCatalogJob;
use App\Models\Content;
use App\Models\Genre;
use App\Models\ImportLog;
use App\Models\Setting;
use App\Models\SourceTitle;
use App\Services\Import\EpisodeRefresher;
use App\Services\Import\ImportService;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class ImportController extends Controller
{
    public function __construct(
        private SourceRegistry $registry,
        private ImportService $importer,
    ) {}

    public function index(Request $request): View
    {
        $sourceId = $request->query('source', array_key_first($this->registry->all()));
        $q = trim((string) $request->query('q', ''));
        $filter = $request->query('filter', 'all'); // all | new | imported

        $titles = SourceTitle::where('source', $sourceId)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('clean_title', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%")))
            ->when($filter === 'new', fn ($w) => $w->notImported())
            ->when($filter === 'imported', fn ($w) => $w->imported())
            ->orderByDesc('view_count')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        $sources = collect($this->registry->all())->map(fn ($s, $id) => [
            'id' => $id,
            'name' => $s->displayName(),
            'synced' => SourceTitle::where('source', $id)->count(),
            'imported' => SourceTitle::where('source', $id)->imported()->count(),
        ])->values();

        return view('admin.import.index', [
            'sources' => $sources,
            'sourceId' => $sourceId,
            'currentSource' => $this->registry->get($sourceId),
            'titles' => $titles,
            'q' => $q,
            'filter' => $filter,
            'genres' => Genre::orderBy('sort')->get(),
            'agent' => \App\Support\IngestAgent::status(),
            'duplicates' => $this->duplicateHints($titles->getCollection()),
            'pending' => SourceTitle::where('source', $sourceId)->notImported()->count(),
            'autoImport' => Setting::flag('auto_import_enabled', false),
            'autoImportSchedules' => $this->scheduleMap(),
        ]);
    }

    /**
     * Per-source auto-import schedule for the admin table: every registered source mapped to
     * {name, enabled, time, days[], limit}. Saved values (JSON `auto_import_schedules`) win; sources
     * not yet configured fall back to the legacy global time/days and the legacy `auto_import_sources`
     * enabled-list, so the table shows sane defaults on first open (nothing silently changes).
     *
     * @return array<string,array{name:string,enabled:bool,time:string,days:array<int,int>,limit:int}>
     */
    private function scheduleMap(): array
    {
        $saved = json_decode((string) Setting::get('auto_import_schedules', ''), true);
        $saved = is_array($saved) ? $saved : [];

        $legacyEnabled = collect(explode(',', (string) Setting::get('auto_import_sources', '24hdx,wowdrama,anime108,rongyok,anifume')))
            ->map(fn ($s) => trim($s))->filter()->all();
        $legacyTime = $this->normalizeTime(Setting::get('auto_import_time', '05:00'));
        $legacyDays = $this->normalizeDays(Setting::get('auto_import_days', ''));
        $legacyLimit = (int) (Setting::get('auto_import_per_run', 40) ?: 40);

        $map = [];
        foreach ($this->registry->all() as $id => $src) {
            $cfg = is_array($saved[$id] ?? null) ? $saved[$id] : null;
            $map[$id] = [
                'name' => $src->displayName(),
                'enabled' => $cfg ? (bool) ($cfg['enabled'] ?? false) : in_array($id, $legacyEnabled, true),
                'time' => $this->normalizeTime($cfg['time'] ?? $legacyTime),
                'days' => $this->normalizeDays($cfg['days'] ?? $legacyDays),
                'limit' => (int) ($cfg['limit'] ?? $legacyLimit) ?: $legacyLimit,
            ];
        }

        return $map;
    }

    /** Save the per-source auto-import schedule (each source's own time/weekdays/limit). Read back in routes/console.php. */
    public function autoSchedule(Request $request): RedirectResponse
    {
        $request->validate([
            'sched' => ['array'],
            'sched.*.time' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'sched.*.days' => ['array'],
            'sched.*.days.*' => ['integer', 'between:0,6'],
            'sched.*.limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $in = (array) $request->input('sched', []);
        $map = [];
        foreach ($this->registry->all() as $id => $src) {
            $row = is_array($in[$id] ?? null) ? $in[$id] : [];
            $map[$id] = [
                'enabled' => isset($row['enabled']) && in_array((string) $row['enabled'], ['1', 'on', 'true', 'yes'], true),
                'time' => $this->normalizeTime($row['time'] ?? '05:00'),
                'days' => $this->normalizeDays($row['days'] ?? []),
                'limit' => (int) ($row['limit'] ?? 40) ?: 40,
            ];
        }

        Setting::write('auto_import_schedules', json_encode($map, JSON_UNESCAPED_UNICODE));

        $onCount = collect($map)->where('enabled', true)->count();

        return back()->with('status', "บันทึกตารางเวลานำเข้าแยกตามแหล่งแล้ว — เปิดใช้งาน {$onCount} แหล่ง");
    }

    /** Normalise "7:5" style input to zero-padded HH:MM; anything invalid → 05:00. */
    private function normalizeTime($value): string
    {
        $value = (string) $value;
        if (! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value)) {
            return '05:00';
        }
        [$h, $m] = array_pad(explode(':', $value), 2, '00');

        return sprintf('%02d:%02d', (int) $h, (int) $m);
    }

    /**
     * Normalise weekdays to a sorted, unique list of ints 0-6 (accepts an array or a CSV string).
     *
     * @return array<int,int>
     */
    private function normalizeDays($value): array
    {
        $list = is_array($value)
            ? $value
            : array_filter(explode(',', (string) $value), fn ($d) => trim($d) !== '');
        $out = array_values(array_unique(array_filter(
            array_map('intval', $list),
            fn ($d) => $d >= 0 && $d <= 6,
        )));
        sort($out);

        return $out;
    }

    /** Turn the daily auto-import (netwix:auto-import) on/off. */
    public function autoToggle(Request $request): RedirectResponse
    {
        $on = $request->boolean('enabled');
        Setting::write('auto_import_enabled', $on ? '1' : '0');

        return back()->with('status', $on
            ? 'เปิดนำเข้าหนังใหม่อัตโนมัติแล้ว — ระบบจะดึงหนังใหม่ให้เองทุกวัน'
            : 'ปิดการนำเข้าอัตโนมัติแล้ว');
    }

    /**
     * For the titles shown, flag any whose normalised title already matches a content in the
     * catalogue that isn't this exact source-title's own import — i.e. "this show may already be in
     * NetWix (perhaps from another source)". Surfaced so the admin can decide, not auto-skipped.
     *
     * @param  \Illuminate\Support\Collection<int,SourceTitle>  $titles
     * @return array<int,string>  sourceTitle id → existing content title
     */
    private function duplicateHints($titles): array
    {
        $byKey = Cache::remember('admin:content_dedupe_map', now()->addSeconds(30), function () {
            $map = [];
            Content::query()->select('id', 'title', 'slug', 'source', 'source_key')->get()->each(function ($c) use (&$map) {
                $key = Content::dedupeKey($c->title ?: $c->slug);
                if ($key !== '' && ! isset($map[$key])) {
                    $map[$key] = ['title' => $c->title, 'source' => $c->source, 'source_key' => (string) $c->source_key];
                }
            });

            return $map;
        });

        $hints = [];
        foreach ($titles as $st) {
            $hit = $byKey[Content::dedupeKey($st->displayTitle())] ?? null;
            $isOwn = $hit && $hit['source'] === $st->source && $hit['source_key'] === (string) $st->source_key;
            if ($hit && ! $isOwn) {
                $hints[$st->id] = $hit['title'];
            }
        }

        return $hints;
    }

    /**
     * Kick off a catalogue sync in the BACKGROUND (dispatch [SyncCatalogJob] to the `sync` queue, drained
     * by the scheduled worker) and return immediately. A full scrape outran Cloudflare's ~100s timeout →
     * the browser retried while the first run was still going → 5+ stacked 30-min scrapes (incident
     * 2026-07-06). Now the request just queues the job; the admin UI polls [syncProgress]. Single-flight:
     * a start while a run is already queued/running is a no-op that just reports the existing run.
     */
    public function sync(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'max_pages' => ['nullable', 'integer', 'between:1,100'],
        ]);
        abort_unless($this->registry->has($data['source']), 404);
        $source = $data['source'];
        $key = 'sync:'.$source;

        // Already running/queued? Don't dispatch a second — just point the UI at the live run. (A queued
        // job that never got picked up within 2 min is treated as stale so a genuine retry can proceed.)
        $status = (string) Cache::get("{$key}:status", 'idle');
        $queuedAt = (int) Cache::get("{$key}:queued_at", 0);
        $active = $status === 'running' || ($status === 'queued' && (time() - $queuedAt) < 120);
        if ($active) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'queued' => true, 'already' => true, 'status' => $status,
                    'message' => 'กำลังซิงค์แหล่งนี้อยู่แล้ว']);
            }

            return back()->with('status', 'กำลังซิงค์แหล่งนี้อยู่แล้ว');
        }

        $ttl = now()->addHours(2);
        Cache::put("{$key}:status", 'queued', $ttl);
        Cache::put("{$key}:queued_at", time(), $ttl);
        Cache::put("{$key}:synced", 0, $ttl);
        Cache::put("{$key}:added", 0, $ttl);
        Cache::forget("{$key}:stop");
        Cache::forget("{$key}:error");
        Cache::forget("{$key}:message");

        SyncCatalogJob::dispatch($source, $data['max_pages'] ?? 100)->onQueue('sync');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'queued' => true, 'message' => 'เริ่มซิงค์แล้ว — ทำงานเบื้องหลัง']);
        }

        return back()->with('status', 'เริ่มซิงค์แคตตาล็อกแล้ว (ทำงานเบื้องหลัง) — จำนวนจะอัปเดตเมื่อเสร็จ');
    }

    /** Live progress of a source's background sync — polled by the admin UI. */
    public function syncProgress(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', '');
        abort_unless($this->registry->has($source), 404);
        $key = 'sync:'.$source;

        return response()->json([
            'status' => (string) Cache::get("{$key}:status", 'idle'), // idle|queued|running|done|stopped|error
            'synced' => (int) Cache::get("{$key}:synced", 0),
            'added' => (int) Cache::get("{$key}:added", 0),
            'total' => SourceTitle::where('source', $source)->count(),
            'message' => Cache::get("{$key}:message"),
            'error' => Cache::get("{$key}:error"),
        ]);
    }

    /** Ask a running background sync to stop at the next page boundary (it flips to "stopped"). */
    public function syncStop(Request $request): JsonResponse
    {
        $source = (string) $request->input('source', '');
        abort_unless($this->registry->has($source), 404);
        Cache::put('sync:'.$source.':stop', true, now()->addHours(2));

        return response()->json(['ok' => true]);
    }

    /**
     * Per-title "รีเฟรชตอน" button: re-scrape one imported title's episode list from the source —
     * new episodes of an airing series arrive immediately, and a series mis-typed as a 1-episode
     * movie is corrected. Selection rules + the actual refresh live in [EpisodeRefresher].
     */
    public function refreshEpisodes(Request $request, EpisodeRefresher $refresher): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer']]);
        $st = SourceTitle::imported()->findOrFail($data['id']);

        @set_time_limit(120);

        try {
            $r = $refresher->refresh($st);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'รีเฟรชตอนไม่สำเร็จ: '.mb_substr($e->getMessage(), 0, 120),
            ], 500);
        }

        return response()->json(['ok' => true] + $r);
    }

    /**
     * Import a small chunk of titles and return JSON — the admin UI calls this repeatedly to drive a
     * live progress bar (so a big selection never blocks in one long request). Works for every source;
     * auto_type/auto_genres only take effect where the source carries that metadata (anime108).
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'ids' => ['required', 'array', 'min:1', 'max:10'],
            'ids.*' => ['integer'],
            'type' => ['nullable', 'in:series,movie,vertical'],
            'genres' => ['array'],
            'genres.*' => ['integer', 'exists:genres,id'],
            'primary_genre' => ['nullable', 'integer'],
            'publish' => ['sometimes', 'boolean'],
            'auto_type' => ['sometimes', 'boolean'],
            'auto_genres' => ['sometimes', 'boolean'],
            'maturity' => ['nullable', 'in:'.implode(',', \App\Support\Maturity::ADULT)],
        ]);
        abort_unless($this->registry->has($data['source']), 404);

        @set_time_limit(0);

        $opts = [
            'type' => $data['type'] ?? $this->registry->get($data['source'])->defaultContentType(),
            'genres' => $data['genres'] ?? [],
            'primary_genre' => $data['primary_genre'] ?? null,
            'publish' => $request->boolean('publish'),
            'auto_type' => $request->boolean('auto_type'),
            'auto_genres' => $request->boolean('auto_genres'),
            'maturity' => $data['maturity'] ?? null,
        ];

        $titles = SourceTitle::where('source', $data['source'])->whereIn('id', $data['ids'])->get()->keyBy('id');

        $results = [];
        foreach ($data['ids'] as $id) {
            $st = $titles->get($id);
            if (! $st) {
                $results[] = ['id' => $id, 'ok' => false, 'title' => "#{$id}", 'error' => 'ไม่พบเรื่อง'];

                continue;
            }
            try {
                $content = $this->importer->import($st, $opts);
                $results[] = $content === null
                    ? ['id' => $id, 'ok' => true, 'title' => $st->displayTitle(), 'type' => 'ข้าม—เล่นไม่ได้', 'episodes' => 0]
                    : ['id' => $id, 'ok' => true, 'title' => $st->displayTitle(), 'type' => $content->type, 'episodes' => $content->episodes()->count()];
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'ok' => false, 'title' => $st->displayTitle(), 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        ImportLog::record(
            $data['source'], 'manual',
            collect($results)->where('ok', true)->where('type', '!=', 'ข้าม—เล่นไม่ได้')->count(),
            collect($results)->where('type', 'ข้าม—เล่นไม่ได้')->count(),
            collect($results)->where('ok', false)->count(),
        );

        return response()->json([
            'ok' => collect($results)->where('ok', true)->count(),
            'failed' => collect($results)->where('ok', false)->count(),
            'results' => $results,
        ]);
    }

    /**
     * Auto-import driver: picks the next chunk of NOT-yet-imported titles itself (highest view count
     * first) and imports them, returning how many are still left. The admin UI calls this in a loop
     * ("นำเข้าทั้งหมดอัตโนมัติ") until `remaining` hits 0. `exclude` carries ids that already failed this
     * run so a persistently-broken title is attempted once and then skipped — never an infinite loop.
     */
    public function auto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'type' => ['nullable', 'in:series,movie,vertical'],
            'genres' => ['array'],
            'genres.*' => ['integer', 'exists:genres,id'],
            'primary_genre' => ['nullable', 'integer'],
            'publish' => ['sometimes', 'boolean'],
            'auto_type' => ['sometimes', 'boolean'],
            'auto_genres' => ['sometimes', 'boolean'],
            'maturity' => ['nullable', 'in:'.implode(',', \App\Support\Maturity::ADULT)],
            'chunk' => ['nullable', 'integer', 'between:1,8'],
            'exclude' => ['array'],
            'exclude.*' => ['integer'],
        ]);
        abort_unless($this->registry->has($data['source']), 404);

        @set_time_limit(0);

        $opts = [
            'type' => $data['type'] ?? $this->registry->get($data['source'])->defaultContentType(),
            'genres' => $data['genres'] ?? [],
            'primary_genre' => $data['primary_genre'] ?? null,
            'publish' => $request->boolean('publish'),
            'auto_type' => $request->boolean('auto_type'),
            'auto_genres' => $request->boolean('auto_genres'),
            'maturity' => $data['maturity'] ?? null,
        ];
        $exclude = $data['exclude'] ?? [];

        $titles = SourceTitle::where('source', $data['source'])->notImported()
            ->when($exclude, fn ($w) => $w->whereNotIn('id', $exclude))
            ->orderByDesc('view_count')->orderByDesc('id')
            ->limit($data['chunk'] ?? 5)->get();

        $results = [];
        $failedNow = [];
        foreach ($titles as $st) {
            try {
                $content = $this->importer->import($st, $opts);
                $results[] = $content === null
                    ? ['id' => $st->id, 'ok' => true, 'title' => $st->displayTitle(), 'type' => 'ข้าม—เล่นไม่ได้', 'episodes' => 0]
                    : ['id' => $st->id, 'ok' => true, 'title' => $st->displayTitle(), 'type' => $content->type, 'episodes' => $content->episodes()->count()];
            } catch (\Throwable $e) {
                $failedNow[] = $st->id;
                $results[] = ['id' => $st->id, 'ok' => false, 'title' => $st->displayTitle(), 'error' => mb_substr($e->getMessage(), 0, 120)];
            }
        }

        // Titles still to go, ignoring everything that has already failed (this run + earlier).
        $remaining = SourceTitle::where('source', $data['source'])->notImported()
            ->whereNotIn('id', array_merge($exclude, $failedNow) ?: [0])
            ->count();

        ImportLog::record(
            $data['source'], 'manual',
            collect($results)->where('ok', true)->where('type', '!=', 'ข้าม—เล่นไม่ได้')->count(),
            collect($results)->where('type', 'ข้าม—เล่นไม่ได้')->count(),
            collect($results)->where('ok', false)->count(),
        );

        return response()->json([
            'ok' => collect($results)->where('ok', true)->count(),
            'failed' => collect($results)->where('ok', false)->count(),
            'results' => $results,
            'remaining' => $remaining,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string'],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'type' => ['nullable', 'in:series,movie,vertical'],
            'genres' => ['array'],
            'genres.*' => ['integer', 'exists:genres,id'],
            'primary_genre' => ['nullable', 'integer'],
            'publish' => ['sometimes', 'boolean'],
            'auto_type' => ['sometimes', 'boolean'],
            'auto_genres' => ['sometimes', 'boolean'],
            'maturity' => ['nullable', 'in:'.implode(',', \App\Support\Maturity::ADULT)],
        ]);
        abort_unless($this->registry->has($data['source']), 404);

        // NetWix resolves signed CDN URLs itself at watch time, so importing no longer depends on
        // the home downloader being connected.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $opts = [
            'type' => $data['type'] ?? $this->registry->get($data['source'])->defaultContentType(),
            'genres' => $data['genres'] ?? [],
            'primary_genre' => $data['primary_genre'] ?? null,
            'auto_type' => $request->boolean('auto_type'),
            'auto_genres' => $request->boolean('auto_genres'),
            'publish' => $request->boolean('publish'),
            'maturity' => $data['maturity'] ?? null,
        ];

        $titles = SourceTitle::where('source', $data['source'])->whereIn('id', $data['ids'])->get();

        $ok = 0;
        $skipped = 0;
        $failed = [];
        foreach ($titles as $st) {
            try {
                $this->importer->import($st, $opts) === null ? $skipped++ : $ok++;
            } catch (\Throwable $e) {
                $failed[] = $st->displayTitle();
            }
        }

        ImportLog::record($data['source'], 'manual', $ok, $skipped, count($failed), 'เลือกนำเข้าเอง');

        $msg = "นำเข้าสำเร็จ {$ok} เรื่อง";
        if ($skipped) {
            $msg .= " · ข้าม {$skipped} เรื่อง (ตัวเล่นเล่นไม่ได้)";
        }
        if ($failed) {
            $msg .= ' · ไม่สำเร็จ '.count($failed).' เรื่อง ('.implode(', ', array_slice($failed, 0, 3)).')';
        }

        return redirect()
            ->route('admin.import.index', ['source' => $data['source']])
            ->with('status', $msg);
    }
}
