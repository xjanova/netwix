<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SeedEpisodeThumbs;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Genre;
use App\Support\EpisodeThumbScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Batch episode-cover generation with a live, RESUMABLE progress bar.
 *
 * ffmpeg cannot run in this (php-fpm) request — proc_open/exec are disabled for
 * the web SAPI — so covers are produced by [GenerateEpisodeThumb] jobs drained by
 * the scheduled CLI workers (see routes/console.php).
 *
 * The whole run is driven SERVER-SIDE: `begin` dispatches one [SeedEpisodeThumbs]
 * job that walks the scope and enqueues the per-episode jobs. The browser only
 * polls. That means the run keeps going after the page/browser is closed, and a
 * reopened page re-attaches to it via `active` instead of showing a blank slate
 * and tempting the admin to re-issue the whole thing.
 *
 * State lives in cache under `thumbs:{batch}:*` (+ a `thumbs:active` pointer):
 *   total       — denominator snapshot at begin
 *   seeded      — episodes enqueued so far (drives the "ส่งเข้าคิว %" bar)
 *   seed_done   — the seeder has finished enqueueing the whole scope
 *   proc / fail — episodes processed / failed by the gen jobs
 *   last        — last processed episode (for the live log)
 *   stop        — hard-stop flag (already-queued jobs skip on pickup)
 * plus a Redis set `netwix:thumbs:{batch}:failed` of failed episode ids (redo).
 */
class ThumbController extends Controller
{
    private const TTL_HOURS = 48;   // a whole-site run takes many hours; state must outlive it

    public function index(): View
    {
        $published = fn ($q) => $q->where('is_published', true);
        $total = Episode::whereHas('content', $published)->count();
        $missing = Episode::whereNull('thumbnail_path')->whereHas('content', $published)->count();
        $genres = Genre::orderBy('sort')->get(['id', 'name']);

        return view('admin.thumbs.index', compact('total', 'missing', 'genres'));
    }

    /** Title autocomplete for the "by title" scope. */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 1) {
            return response()->json(['items' => []]);
        }

        $items = Content::where('title', 'like', "%{$term}%")
            ->withCount('episodes')->orderByDesc('episodes_count')->take(12)
            ->get(['id', 'title'])
            ->map(fn (Content $c) => ['id' => $c->id, 'title' => $c->title, 'episodes' => $c->episodes_count]);

        return response()->json(['items' => $items]);
    }

    /** Open a batch: snapshot the denominator + kick off server-side seeding. */
    public function begin(Request $request): JsonResponse
    {
        $this->supersede();  // one run at a time — clear any previous run's queued jobs first
        $params = $this->params($request);
        $total = EpisodeThumbScope::query($params)->count();
        $batch = 'g'.Str::random(10);
        $priority = $total > 0 && $total <= 500; // small runs jump the bulk backlog
        $genQueue = $priority ? 'thumbs-now' : 'thumbs';

        $this->openBatch($batch, [
            'label' => $this->label($params, $request),
            'mode' => 'scope',
            'seed_total' => $total,
            'priority' => $priority,
        ], $total);

        if ($total > 0) {
            SeedEpisodeThumbs::dispatch($batch, $params, $genQueue)->onQueue('thumbs-now');
        } else {
            Cache::put("thumbs:{$batch}:seed_done", true, $this->ttl());
        }

        return response()->json(['batch' => $batch, 'total' => $total]);
    }

    /** Re-run ONLY the episodes that failed in a previous batch — no rescan. */
    public function redoFailed(Request $request): JsonResponse
    {
        $this->supersede();  // one run at a time — clear any previous run's queued jobs first
        $source = (string) $request->input('batch');
        $ids = [];
        try {
            $ids = array_values(array_unique(array_map(
                'intval',
                Redis::smembers("netwix:thumbs:{$source}:failed") ?: []
            )));
        } catch (Throwable $e) {
            // fall through as "nothing to redo"
        }

        if (empty($ids)) {
            return response()->json(['batch' => null, 'total' => 0]);
        }

        $total = count($ids);
        $batch = 'g'.Str::random(10);
        $priority = $total <= 500;
        $genQueue = $priority ? 'thumbs-now' : 'thumbs';

        Cache::put("thumbs:{$batch}:seedids", $ids, $this->ttl());
        $this->openBatch($batch, [
            'label' => 'ทำซ้ำเฉพาะที่พลาด',
            'mode' => 'ids',
            'seed_total' => $total,
            'priority' => $priority,
        ], $total);

        // force=false: failed episodes still have a null thumbnail, so a normal
        // (non-force) attempt regenerates them without touching anything else.
        SeedEpisodeThumbs::dispatch($batch, ['force' => false], $genQueue, 0, 'ids', 0)->onQueue('thumbs-now');

        return response()->json(['batch' => $batch, 'total' => $total]);
    }

    /** Poll a specific batch's progress. */
    public function progress(Request $request): JsonResponse
    {
        $batch = (string) $request->query('batch', (string) $request->input('batch'));

        return response()->json($this->snapshot($batch));
    }

    /**
     * Re-attach point for a freshly-loaded page: returns the current server-side
     * run (if any) so the UI can resume showing it instead of a blank form.
     */
    public function active(): JsonResponse
    {
        $batch = (string) Cache::get('thumbs:active', '');
        if ($batch === '' || ! Cache::has("thumbs:{$batch}:total")) {
            return response()->json(['active' => null]);
        }

        return response()->json(['active' => $batch] + $this->snapshot($batch));
    }

    /** Hard-stop: seeder aborts + queued-but-unprocessed jobs skip their work. */
    public function stop(Request $request): JsonResponse
    {
        $batch = (string) $request->input('batch');
        if ($batch !== '') {
            Cache::put("thumbs:{$batch}:stop", true, $this->ttl());
        }

        return response()->json(['ok' => true]);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * One run at a time. Cancel the previous run and DROP its still-queued jobs so a
     * fresh run starts immediately. Without this a new batch's jobs pile up BEHIND the
     * old backlog: a stacked "whole site" run left ~340k jobs ahead of a genre run, so
     * the workers kept draining the old batch while the new one sat at 0% with an empty
     * live log forever (the "report is wrong / log disappeared" bug). In-flight
     * (reserved) jobs are left alone to finish cleanly; the old batch's stop flag makes
     * them no-op on pickup.
     */
    private function supersede(): void
    {
        $prev = (string) Cache::get('thumbs:active', '');
        if ($prev !== '') {
            Cache::put("thumbs:{$prev}:stop", true, $this->ttl());
        }
        try {
            do {
                $deleted = DB::table('jobs')
                    ->whereIn('queue', ['thumbs', 'thumbs-now'])
                    ->whereNull('reserved_at')      // don't yank a job a worker is mid-processing
                    ->limit(10000)                  // bounded chunks → no one giant table lock
                    ->delete();
            } while ($deleted > 0);
        } catch (Throwable $e) {
            // best-effort — a fresh run is still correct even if a few old jobs linger
        }
    }

    /** Reset a batch's counters + register it as the active run. */
    private function openBatch(string $batch, array $meta, int $total): void
    {
        $ttl = $this->ttl();
        Cache::put("thumbs:{$batch}:total", $total, $ttl);
        Cache::put("thumbs:{$batch}:proc", 0, $ttl);
        Cache::put("thumbs:{$batch}:fail", 0, $ttl);
        Cache::put("thumbs:{$batch}:seeded", 0, $ttl);
        Cache::forget("thumbs:{$batch}:stop");
        Cache::forget("thumbs:{$batch}:seed_done");
        Cache::put("thumbs:{$batch}:meta", $meta + ['created_at' => now()->timestamp], $ttl);
        Cache::put('thumbs:active', $batch, $ttl);
    }

    /** The full progress payload the UI polls — shared by progress() + active(). */
    private function snapshot(string $batch): array
    {
        $meta = (array) Cache::get("thumbs:{$batch}:meta", []);
        $total = (int) Cache::get("thumbs:{$batch}:total", 0);
        $proc = (int) Cache::get("thumbs:{$batch}:proc", 0);
        $fail = (int) Cache::get("thumbs:{$batch}:fail", 0);
        $seeded = (int) Cache::get("thumbs:{$batch}:seeded", 0);
        $seedTotal = (int) ($meta['seed_total'] ?? $total);
        $seedDone = (bool) Cache::get("thumbs:{$batch}:seed_done", false);
        $stopped = (bool) Cache::get("thumbs:{$batch}:stop", false);
        $status = $this->status($stopped, $seedDone, $seeded, $proc);

        // Keep the run visible (and its TTL fresh) while the browser is watching.
        if (in_array($status, ['seeding', 'running'], true)) {
            Cache::put('thumbs:active', $batch, $this->ttl());
        }

        return [
            'status' => $status,          // seeding | running | done | stopped
            'label' => $meta['label'] ?? null,
            'total' => $total,
            'processed' => $proc,
            'failed' => $fail,
            'seeded' => $seeded,
            'seed_total' => $seedTotal,
            'seed_done' => $seedDone,
            'last' => Cache::get("thumbs:{$batch}:last"),
            // Jobs enqueued for THIS run but not yet processed. Batch-scoped (not a global
            // jobs-table count) so the number always matches the run the UI is watching, and
            // it's free. supersede() guarantees only this run's jobs sit on the lanes.
            'pending' => max(0, $seeded - $proc),
            // One entry per currently-working agent → the live "Agent" cards.
            'agents' => $this->activeAgents(),
            // How many failures are re-runnable right now (drives the redo button).
            'failed_ids' => $this->failedCount($batch),
            // Real catalogue-wide "still missing a cover" count for the top card.
            // Cached ~5s so a 1.2s poll can't hammer this 240k-row count query.
            'missing' => Cache::remember('thumbs:missing_count', now()->addSeconds(5), fn () => (int) Episode::query()
                ->whereNull('thumbnail_path')
                ->whereHas('content', fn ($c) => $c->where('is_published', true))
                ->count()),
        ];
    }

    /**
     * Derive the run phase from the counters (no stored transitions → no write
     * races between the seeder, the gen jobs and a stop press).
     *
     * "done" compares processed against SEEDED, not the begin-time total: a
     * skip-existing scope can shrink under concurrency, so seeded (jobs actually
     * dispatched) is the true denominator once seeding has finished.
     */
    private function status(bool $stopped, bool $seedDone, int $seeded, int $proc): string
    {
        if ($stopped) {
            return 'stopped';
        }
        if (! $seedDone) {
            return 'seeding';
        }
        if ($seeded === 0 || $proc >= $seeded) {
            return 'done';
        }

        return 'running';
    }

    private function failedCount(string $batch): int
    {
        try {
            return (int) Redis::scard("netwix:thumbs:{$batch}:failed");
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Currently-working agents from the shared activity hash (drops stale PIDs). */
    private function activeAgents(): array
    {
        $agents = [];
        try {
            $raw = Redis::hgetall('netwix:thumbs:agents') ?: [];
            $now = time();
            $stale = [];
            foreach ($raw as $pid => $json) {
                $a = json_decode((string) $json, true);
                if (! is_array($a) || $now - (int) ($a['ts'] ?? 0) > 8) {
                    $stale[] = $pid; // dead/finished worker

                    continue;
                }
                $agents[] = [
                    'title' => $a['title'] ?? '—',
                    'ep' => (int) ($a['ep'] ?? 0),
                    'done' => (int) ($a['done'] ?? 0),
                ];
            }
            if ($stale) {
                Redis::hdel('netwix:thumbs:agents', ...$stale);
            }
            usort($agents, fn ($x, $y) => $y['done'] <=> $x['done']);
        } catch (Throwable $e) {
            // best-effort — no agents panel is fine
        }

        return $agents;
    }

    /** Normalise the request into a scope descriptor for EpisodeThumbScope. */
    private function params(Request $request): array
    {
        return [
            'scope' => (string) $request->input('scope', 'all'),
            'genre_id' => $request->filled('genre_id') ? (int) $request->input('genre_id') : null,
            'content_id' => $request->filled('content_id') ? (int) $request->input('content_id') : null,
            'force' => $request->boolean('force'),
        ];
    }

    /** Human label for the run so a re-attached page can say what's running. */
    private function label(array $params, Request $request): string
    {
        $scope = $params['scope'] ?? 'all';
        if ($scope === 'title' && ! empty($params['content_id'])) {
            $title = Content::whereKey($params['content_id'])->value('title');
            $label = 'เรื่อง: '.($title ?: '#'.$params['content_id']);
        } elseif ($scope === 'genre' && ! empty($params['genre_id'])) {
            $name = Genre::whereKey($params['genre_id'])->value('name');
            $label = 'หมวด: '.($name ?: '#'.$params['genre_id']);
        } else {
            $label = 'ทั้งเว็บ';
        }

        return $label.(! empty($params['force']) ? ' (ทำใหม่ทับของเดิม)' : '');
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addHours(self::TTL_HOURS);
    }
}
