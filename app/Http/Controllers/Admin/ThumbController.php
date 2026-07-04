<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Genre;
use App\Support\EpisodeThumbnailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Batch episode-cover generation with a live progress bar. The catalogue has
 * 240k+ episodes, so we DON'T ship ids to the browser: the client just walks a
 * server-side cursor (id > after) a couple at a time, so it can show a live log,
 * a moving count, and pause/stop — and the covers land as WebP via
 * [EpisodeThumbnailer] (download-then-ffmpeg, no queue worker needed).
 */
class ThumbController extends Controller
{
    private const BATCH = 2; // episodes generated per request (each ~10-15s)

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

    /** How many episodes the chosen scope will process (the progress denominator). */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['total' => $this->scoped($request)->count()]);
    }

    /** Generate the next BATCH episodes after the cursor; returns per-episode results. */
    public function run(Request $request, EpisodeThumbnailer $thumbnailer): JsonResponse
    {
        @set_time_limit(0);
        $after = (int) $request->input('after_id', 0);
        $force = $request->boolean('force');

        $episodes = $this->scoped($request)
            ->where('episodes.id', '>', $after)
            ->orderBy('episodes.id')
            ->with('content:id,title')
            ->take(self::BATCH)
            ->get();

        $items = [];
        $next = $after;
        foreach ($episodes as $i => $ep) {
            if ($i > 0) {
                usleep(400_000); // be gentle on the CDN between big downloads
            }
            $status = $thumbnailer->generate($ep, $force);
            $items[] = [
                'id' => $ep->id,
                'title' => $ep->content?->title ?? '—',
                'number' => (int) $ep->number,
                'ok' => in_array($status, ['ok', 'exists'], true),
                'reason' => $status,
            ];
            $next = $ep->id;
        }

        return response()->json(['items' => $items, 'next_after' => $next, 'done' => count($items) > 0]);
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
