<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Episode;
use App\Models\Genre;
use App\Support\EpisodeThumbnailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Batch episode-cover generation. Admin picks a scope (all / genre / title) and
 * clicks once; the page walks the episode ids in small batches with a progress
 * bar, generating a WebP frame for each via [EpisodeThumbnailer]. No more
 * "capture when someone watches".
 */
class ThumbController extends Controller
{
    public function index(): View
    {
        $published = fn ($q) => $q->where('is_published', true);
        $total = Episode::whereHas('content', $published)->count();
        $missing = Episode::whereNull('thumbnail_path')->whereHas('content', $published)->count();
        $genres = Genre::orderBy('sort')->get(['id', 'name']);

        return view('admin.thumbs.index', compact('total', 'missing', 'genres'));
    }

    /** Resolve the episode ids to process for the chosen scope + mode. */
    public function scan(Request $request): JsonResponse
    {
        $scope = (string) $request->input('scope', 'all');   // all | genre | title
        $mode = (string) $request->input('mode', 'missing'); // missing | all (force)

        $q = Episode::query()
            ->whereNotNull('source_ref')
            ->whereHas('content', fn ($c) => $c->where('is_published', true));

        if ($mode === 'missing') {
            $q->whereNull('thumbnail_path');
        }

        if ($scope === 'genre' && $request->filled('genre_id')) {
            $gid = (int) $request->input('genre_id');
            $q->whereHas('content.genres', fn ($g) => $g->where('genres.id', $gid));
        } elseif ($scope === 'title' && $request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $q->whereHas('content', fn ($c) => $c->where('title', 'like', "%{$term}%"));
        }

        $ids = $q->orderBy('id')->pluck('id')->all();

        return response()->json(['ids' => $ids, 'total' => count($ids)]);
    }

    /** Generate covers for one small batch of episode ids (client-paced). */
    public function run(Request $request, EpisodeThumbnailer $thumbnailer): JsonResponse
    {
        @set_time_limit(0);

        $ids = array_slice((array) $request->input('ids', []), 0, 6);
        $force = $request->boolean('force');

        $done = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $episode = Episode::find((int) $id);
            if (! $episode) {
                $failed++;

                continue;
            }
            $thumbnailer->generate($episode, $force) ? $done++ : $failed++;
        }

        return response()->json(['done' => $done, 'failed' => $failed]);
    }
}
