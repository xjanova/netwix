<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateEpisodeThumb;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Genre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Batch episode-cover generation with a live progress bar.
 *
 * ffmpeg CANNOT run in this (php-fpm) request — proc_open/exec are disabled for
 * the web SAPI. So we don't generate here: we DISPATCH one [GenerateEpisodeThumb]
 * per episode onto the `thumbs` queue, which the scheduled CLI worker drains
 * (where ffmpeg works). The browser walks a server-side cursor to enqueue in
 * chunks, then polls a per-batch cache counter for progress + the current title.
 */
class ThumbController extends Controller
{
    private const CHUNK = 400; // episodes enqueued per request (just DB inserts — fast)

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

    /** Open a batch: snapshot the denominator + reset the progress counters. */
    public function begin(Request $request): JsonResponse
    {
        $total = $this->scoped($request)->count();
        $batch = 'g'.Str::random(10);
        $ttl = now()->addHours(6);

        Cache::put("thumbs:{$batch}:total", $total, $ttl);
        Cache::put("thumbs:{$batch}:proc", 0, $ttl);
        Cache::put("thumbs:{$batch}:fail", 0, $ttl);
        Cache::forget("thumbs:{$batch}:stop");

        return response()->json(['batch' => $batch, 'total' => $total]);
    }

    /** Dispatch the next CHUNK of episodes (after the cursor) onto the thumbs queue. */
    public function enqueue(Request $request): JsonResponse
    {
        $after = (int) $request->input('after_id', 0);
        $force = $request->boolean('force');
        $batch = (string) $request->input('batch');
        // Small on-demand runs jump ahead of any big bulk backlog (see routes/console.php).
        $queue = $request->boolean('priority') ? 'thumbs-now' : 'thumbs';

        $episodes = $this->scoped($request)
            ->where('episodes.id', '>', $after)
            ->orderBy('episodes.id')
            ->take(self::CHUNK)
            ->get(['episodes.id']);

        foreach ($episodes as $ep) {
            GenerateEpisodeThumb::dispatch($ep->id, $force, $batch)->onQueue($queue);
        }

        return response()->json([
            'queued' => $episodes->count(),
            'next_after' => $episodes->last()?->id ?? $after,
            'done' => $episodes->count() < self::CHUNK, // last (short) chunk
        ]);
    }

    /** Poll batch progress — works for skip-existing AND force/regenerate runs. */
    public function progress(Request $request): JsonResponse
    {
        $batch = (string) $request->query('batch', (string) $request->input('batch'));

        return response()->json([
            'total' => (int) Cache::get("thumbs:{$batch}:total", 0),
            'processed' => (int) Cache::get("thumbs:{$batch}:proc", 0),
            'failed' => (int) Cache::get("thumbs:{$batch}:fail", 0),
            'last' => Cache::get("thumbs:{$batch}:last"),
            // Live server-side queue depth (both lanes) — proof the workers are alive.
            'pending' => (int) DB::table('jobs')->whereIn('queue', ['thumbs-now', 'thumbs'])->count(),
            // One entry per currently-working agent → the live "Agent" cards.
            'agents' => $this->activeAgents(),
        ]);
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

    /** Hard-stop: queued-but-unprocessed jobs skip their work on pickup. */
    public function stop(Request $request): JsonResponse
    {
        $batch = (string) $request->input('batch');
        if ($batch !== '') {
            Cache::put("thumbs:{$batch}:stop", true, now()->addHours(6));
        }

        return response()->json(['ok' => true]);
    }

    /** Episodes matching the requested scope (+ "skip existing" unless force). */
    private function scoped(Request $request): Builder
    {
        $q = Episode::query()
            ->whereNotNull('source_ref')
            ->whereHas('content', fn ($c) => $c->where('is_published', true));

        if (! $request->boolean('force')) {
            $q->whereNull('thumbnail_path'); // skip episodes that already have a cover
        }

        $scope = (string) $request->input('scope', 'all');
        if ($scope === 'genre' && $request->filled('genre_id')) {
            $q->whereHas('content.genres', fn ($g) => $g->where('genres.id', (int) $request->input('genre_id')));
        } elseif ($scope === 'title' && $request->filled('content_id')) {
            $q->where('content_id', (int) $request->input('content_id'));
        }

        return $q;
    }
}
