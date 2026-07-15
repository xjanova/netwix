<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentResource;
use App\Http\Resources\EpisodeResource;
use App\Models\Content;
use App\Models\Genre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Base query for everything the app lists, mirroring the web's split exactly:
     * a GUEST gets scopePublicListing() — the hard gate the crawlable pages use, so
     * adult (18+/20+) titles are never served to someone without an account — while a
     * signed-in MEMBER gets the full catalogue like BrowseController, with MaturityScope
     * hiding adult from kids profiles. The auth.apptoken.optional middleware is what
     * makes the viewer resolvable on these otherwise-public routes.
     */
    private function viewable(): Builder
    {
        return request()->user() ? Content::published() : Content::publicListing();
    }

    /** GET /api/app/home — hero + rails (public). Mirrors the web BrowseController:
     * anime/cartoon is kept out of the hero and the genre rails (it has its own
     * rail + Anime category) so it doesn't bleed into everything. */
    public function home(): JsonResponse
    {
        $animeIds = $this->animeGenreIds();
        $notAnime = fn ($q) => $q->whereDoesntHave('genres', fn ($g) => $g->whereIn('genres.id', $animeIds));

        $hero = $this->viewable()->where('is_featured', true)->where($notAnime)
            ->with('genres')->withCount('episodes')->inRandomOrder()->first()
            ?? $this->viewable()->where($notAnime)->with('genres')->withCount('episodes')->latest()->first();

        $rails = [];

        $originals = $this->viewable()->where('is_original', true)->where($notAnime)
            ->with('genres')->withCount('episodes')->latest()->take(14)->get();
        if ($originals->isNotEmpty()) {
            $rails[] = ['key' => 'originals', 'title' => 'NETWIX Originals', 'ranked' => false,
                'items' => ContentResource::collection($originals)];
        }

        $trending = $this->viewable()->where($notAnime)->with('genres')->withCount('episodes')
            ->orderByDesc('views')->take(10)->get();
        if ($trending->isNotEmpty()) {
            $rails[] = ['key' => 'trending', 'title' => 'มาแรงตอนนี้', 'ranked' => true,
                'items' => ContentResource::collection($trending)];
        }

        // Dedicated anime/cartoon rail (its own bucket, like the web /anime page).
        if ($animeIds !== []) {
            $anime = $this->viewable()
                ->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $animeIds))
                ->with('genres')->withCount('episodes')->orderByDesc('views')->take(14)->get();
            if ($anime->isNotEmpty()) {
                $rails[] = ['key' => 'anime', 'title' => 'อนิเมะ & การ์ตูน', 'ranked' => false,
                    'items' => ContentResource::collection($anime)];
            }
        }

        // Per-genre rails — anime genres excluded (umbrella + bleed-through).
        foreach (Genre::orderBy('sort')->whereNotIn('id', $animeIds ?: [0])->get() as $genre) {
            $items = $this->viewable()->where($notAnime)
                ->whereHas('genres', fn ($q) => $q->whereKey($genre->id))
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

    /**
     * GET /api/app/titles?type=series|movie|vertical&genre=<slug>&anime=1&page=N&per=24
     *
     * `type` narrows the media type; `genre` narrows to one genre (by slug);
     * `anime=1` returns the anime/cartoon bucket. With no genre/anime param the
     * full catalogue is returned (this list also backs the app's offline cache).
     * Anime stays separated where it matters — the home genre rails and the
     * dedicated Anime category.
     */
    public function titles(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $per = (int) min(48, max(6, (int) $request->query('per', 24)));
        $animeIds = $this->animeGenreIds();

        $q = $this->viewable()->with('genres')->withCount('episodes')->latest();
        if (in_array($type, ['series', 'movie', 'vertical'], true)) {
            $q->where('type', $type);
        }

        if ($request->boolean('anime')) {
            $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $animeIds ?: [0]));
        } elseif ($slug = $request->query('genre')) {
            $q->whereHas('genres', fn ($g) => $g->where('slug', $slug));
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

    /**
     * POST /api/app/content/{id}/view — count a watch. Deduped per viewer
     * (ip) + title for 6h so a refresh/rewatch can't inflate the number.
     * Public: guests watch too.
     */
    public function view(Content $content, Request $request): JsonResponse
    {
        $key = 'view:'.$content->id.':'.sha1((string) $request->ip());
        if (Cache::add($key, 1, now()->addHours(6))) {
            $content->increment('views');
        }

        return $this->ok(['ok' => true]);
    }

    /** GET /api/app/genres — the genre taxonomy for the app's category chips. */
    public function genres(): JsonResponse
    {
        $animeIds = $this->animeGenreIds();

        $items = Genre::orderBy('sort')->get()->map(fn ($g) => [
            'name' => $g->name,
            'name_en' => $g->name_en,
            'slug' => $g->slug,
            'is_anime' => in_array($g->id, $animeIds, true),
        ])->values();

        return $this->ok(['items' => $items]);
    }

    /** @return int[] genre ids for the anime/cartoon bucket (kept off the general lists). */
    private function animeGenreIds(): array
    {
        return Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
    }

    /** GET /api/app/titles/{slug} — detail + episodes + related. */
    public function show(string $slug): JsonResponse
    {
        $content = $this->viewable()->where('slug', $slug)
            ->with(['genres', 'seasons.episodes', 'episodes'])->withCount('episodes')->first();

        if (! $content) {
            return response()->json(['success' => false, 'error' => ['code' => 'not_found', 'message' => 'ไม่พบเนื้อหา']], 404);
        }

        $related = $this->viewable()->whereKeyNot($content->id)
            ->where('type', $content->type)->with('genres')->withCount('episodes')
            ->inRandomOrder()->take(6)->get();

        // Point every episode back at the title already in memory so EpisodeResource
        // can inherit its playback markers without an N+1.
        $content->episodes->each(fn ($e) => $e->setRelation('content', $content));
        $content->seasons->each(
            fn ($s) => $s->episodes->each(fn ($e) => $e->setRelation('content', $content))
        );

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
        $p = $this->viewable()
            ->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('synopsis', 'like', "%{$term}%"))
            ->with('genres')->withCount('episodes')->orderByDesc('views')->paginate($per);

        return $this->ok([
            'items' => ContentResource::collection($p->items()),
            'page' => $p->currentPage(),
            'has_more' => $p->hasMorePages(),
        ]);
    }
}
