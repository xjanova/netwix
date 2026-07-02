<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentResource;
use App\Http\Resources\EpisodeResource;
use App\Models\Content;
use App\Models\Genre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public catalog API for the NetWix mobile app. Mirrors the Blade
 * BrowseController rail logic but returns JSON ({success,data}).
 */
class CatalogController extends Controller
{
    private function ok($data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    /** GET /api/app/home — hero + rails (public). */
    public function home(): JsonResponse
    {
        $hero = Content::published()->where('is_featured', true)
            ->with('genres')->withCount('episodes')->inRandomOrder()->first()
            ?? Content::published()->with('genres')->withCount('episodes')->latest()->first();

        $rails = [];

        $originals = Content::published()->where('is_original', true)
            ->with('genres')->withCount('episodes')->latest()->take(14)->get();
        if ($originals->isNotEmpty()) {
            $rails[] = ['key' => 'originals', 'title' => 'NETWIX Originals', 'ranked' => false,
                'items' => ContentResource::collection($originals)];
        }

        $trending = Content::published()->with('genres')->withCount('episodes')
            ->orderByDesc('views')->take(10)->get();
        if ($trending->isNotEmpty()) {
            $rails[] = ['key' => 'trending', 'title' => 'มาแรงตอนนี้', 'ranked' => true,
                'items' => ContentResource::collection($trending)];
        }

        foreach (Genre::orderBy('sort')->get() as $genre) {
            $items = Content::published()->whereHas('genres', fn ($q) => $q->whereKey($genre->id))
                ->with('genres')->withCount('episodes')->latest()->take(14)->get();
            if ($items->count() >= 3) {
                $rails[] = ['key' => 'genre:'.$genre->slug, 'title' => $genre->name, 'ranked' => false,
                    'items' => ContentResource::collection($items)];
            }
        }

        return $this->ok([
            'hero' => $hero ? new ContentResource($hero) : null,
            'rails' => $rails,
        ]);
    }

    /** GET /api/app/titles?type=series|movie|vertical&page=N&per=24 */
    public function titles(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $per = (int) min(48, max(6, (int) $request->query('per', 24)));

        $q = Content::published()->with('genres')->withCount('episodes')->latest();
        if (in_array($type, ['series', 'movie', 'vertical'], true)) {
            $q->where('type', $type);
        }

        $p = $q->paginate($per);

        return $this->ok([
            'items' => ContentResource::collection($p->items()),
            'page' => $p->currentPage(),
            'per' => $p->perPage(),
            'total' => $p->total(),
            'has_more' => $p->hasMorePages(),
        ]);
    }

    /** GET /api/app/titles/{slug} — detail + episodes + related. */
    public function show(string $slug): JsonResponse
    {
        $content = Content::published()->where('slug', $slug)
            ->with(['genres', 'seasons.episodes', 'episodes'])->withCount('episodes')->first();

        if (! $content) {
            return response()->json(['success' => false, 'error' => ['code' => 'not_found', 'message' => 'ไม่พบเนื้อหา']], 404);
        }

        $related = Content::published()->whereKeyNot($content->id)
            ->where('type', $content->type)->with('genres')->withCount('episodes')
            ->inRandomOrder()->take(6)->get();

        return $this->ok([
            'content' => new ContentResource($content),
            'seasons' => $content->seasons->map(fn ($s) => [
                'id' => $s->id, 'number' => $s->number, 'title' => $s->title,
                'episodes' => EpisodeResource::collection($s->episodes),
            ])->values(),
            'episodes' => EpisodeResource::collection($content->episodes),
            'related' => ContentResource::collection($related),
        ]);
    }

    /** GET /api/app/search?q=... */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return $this->ok(['items' => [], 'has_more' => false]);
        }

        $per = 40;
        $p = Content::published()
            ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('synopsis', 'like', "%{$term}%"))
            ->with('genres')->withCount('episodes')->orderByDesc('views')->paginate($per);

        return $this->ok([
            'items' => ContentResource::collection($p->items()),
            'page' => $p->currentPage(),
            'has_more' => $p->hasMorePages(),
        ]);
    }
}
