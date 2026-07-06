<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Genre;
use App\Models\Profile;
use App\Models\Setting;
use App\Services\Recommender;
use App\Support\HeroBillboard;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BrowseController extends Controller
{
    public function home(Request $request): View
    {
        $profile = $this->profile($request);
        $animeIds = $this->animeGenreIds();
        $notAnime = fn ($q) => $q->whereDoesntHave('genres', fn ($g) => $g->whereIn('genres.id', $animeIds));

        $rows = [];

        // Continue watching (most-recent first)
        if ($c = $this->continueRow($profile, null, 'notanime')) {
            $rows[] = $c;
        }

        // NetWix Originals
        $originals = Content::published()->where('is_original', true)
            ->with(['genres', 'previewEpisode'])->latest()->take(14)->get();
        if ($originals->isNotEmpty()) {
            $rows[] = ['title' => 'NETWIX Originals', 'items' => $originals];
        }

        // Trending (by views)
        $rows[] = [
            'title' => 'มาแรงตอนนี้',
            'en' => 'Trending Now',
            'ranked' => true,
            'items' => Content::published()->trending()->with(['genres', 'previewEpisode'])->take(10)->get(),
        ];

        // My list
        $myList = $profile->myList()->published()->with(['genres', 'previewEpisode'])->get();
        if ($myList->isNotEmpty()) {
            $rows[] = ['title' => 'รายการของฉัน', 'en' => 'My List', 'items' => $myList];
        }

        // Per-genre rows — anime/cartoon lives on its own /anime page, so it's kept out here. Each
        // row lazy-loads EVERY title in that genre as you slide it (page 1 here, page 2+ via
        // browse.row); a per-load seed keeps the seeded shuffle aligned across pages.
        $rowSeed = random_int(1, 999999);
        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue;
            }
            $items = $this->rowQuery(null, $genre->id, 'notanime', $rowSeed)->take(18)->get();
            if ($items->count() >= 3) {
                $rows[] = ['title' => $genre->name, 'en' => $genre->name_en, 'items' => $items,
                    'link' => route('browse.genre', $genre),
                    'lazy' => ['genre' => $genre->id, 'scope' => 'notanime', 'seed' => $rowSeed]];
            }
        }

        return view('frontend.browse', [
            'heroSlides' => HeroBillboard::slides('all'),   // whole site (cached ~2 min, shared by all visitors)
            'heroSeconds' => HeroBillboard::seconds(),
            'heroVideo' => HeroBillboard::videoEnabled(),
            'rows' => $rows,
            'myListIds' => $this->myListIds($profile),
            'feedSeed' => random_int(1, 999999),
            'feedGenres' => Genre::orderBy('sort')->whereNotIn('id', $animeIds)->get(['id', 'name']),
        ]);
    }

    /** Dedicated อนิเมะ/การ์ตูน page — same browse template, only anime content. */
    public function anime(Request $request): View
    {
        $profile = $this->profile($request);
        $animeIds = $this->animeGenreIds();

        if ($animeIds === []) {
            return view('frontend.browse', [
                'hero' => null, 'rows' => [], 'heading' => 'อนิเมะ / การ์ตูน',
                'myListIds' => $this->myListIds($profile),
            ]);
        }
        $isAnime = fn ($q) => $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $animeIds));

        $rows = [];
        // Continue watching first (anime only).
        if ($c = $this->continueRow($profile, null, 'anime')) {
            $rows[] = $c;
        }
        $rows[] = [
            'title' => 'อนิเมะมาแรง', 'en' => 'Trending Anime', 'ranked' => true,
            'items' => Content::published()->where($isAnime)->trending()
                ->with(['genres', 'previewEpisode'])->take(10)->get(),
        ];

        $rowSeed = random_int(1, 999999);
        $baseCount = count($rows);
        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue; // skip the umbrella genres — group by the real sub-genres instead
            }
            $items = $this->rowQuery(null, $genre->id, 'anime', $rowSeed)->take(18)->get();
            if ($items->count() >= 3) {
                $rows[] = ['title' => $genre->name, 'en' => $genre->name_en, 'items' => $items,
                    'link' => route('browse.genre', $genre),
                    'lazy' => ['genre' => $genre->id, 'scope' => 'anime', 'seed' => $rowSeed]];
            }
        }

        if (count($rows) === $baseCount) { // no sub-genre rows filled → one catch-all row
            $rows[] = [
                'title' => 'อนิเมะทั้งหมด',
                'items' => Content::published()->where($isAnime)->with(['genres', 'previewEpisode'])
                    ->inRandomOrder()->take(24)->get(),
            ];
        }

        return view('frontend.browse', [
            'heroSlides' => HeroBillboard::slides('anime'),   // rotates within อนิเมะ only
            'heroSeconds' => HeroBillboard::seconds(),
            'heroVideo' => HeroBillboard::videoEnabled(),
            'rows' => $rows, 'heading' => 'อนิเมะ / การ์ตูน',
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    /** @return int[] genre ids for the anime/cartoon section, kept off the main browse. */
    private function animeGenreIds(): array
    {
        return Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
    }

    /** Personalised infinite-scroll feed page (JSON of rendered cards). */
    public function feed(Request $request, Recommender $recommender): JsonResponse
    {
        $profile = $this->profile($request);
        $seed = (int) $request->query('seed', 1);
        $page = max(1, (int) $request->query('page', 1));
        $genre = ($g = $request->query('genre')) ? (int) $g : null;
        $perPage = 18;

        $items = $recommender->feedQuery($profile, $seed, $genre)
            ->forPage($page, $perPage)->get();

        return response()->json([
            'html' => view('frontend.partials.feed-cards', [
                'items' => $items,
                'myListIds' => $this->myListIds($profile),
            ])->render(),
            'done' => $items->count() < $perPage,
            'next' => $page + 1,
        ]);
    }

    /**
     * Shared query for a lazy genre rail — used BOTH for the first page rendered server-side and for
     * the JSON pages fetched as you slide the row, so they stay perfectly aligned (seeded shuffle →
     * no repeats/skips across pages). $scope keeps anime in/out to match the page it's shown on.
     */
    private function rowQuery(?string $type, ?int $genreId, ?string $scope, int $seed)
    {
        $q = Content::published()->with(['genres', 'previewEpisode']);
        if ($type) {
            $q->type($type);
        }
        if ($type === 'vertical') {
            $q->withCount('episodes');
        }
        if ($scope === 'notanime') {
            $ids = $this->animeGenreIds();
            $q->whereDoesntHave('genres', fn ($g) => $g->whereIn('genres.id', $ids));
        } elseif ($scope === 'anime') {
            $ids = $this->animeGenreIds();
            $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $ids));
        }
        if ($genreId) {
            $q->whereHas('genres', fn ($g) => $g->where('genres.id', $genreId));
        }

        return $q->inRandomOrder($seed + ($genreId ?? 0));
    }

    /** Lazy genre rail — one JSON page of cards for {type, genre, scope, seed, page}. */
    public function row(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $type = $request->query('type') ?: null;
        $genreId = ($g = $request->query('genre')) ? (int) $g : null;
        $scope = $request->query('scope') ?: null;
        $seed = (int) $request->query('seed', 1);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 18;

        $items = $this->rowQuery($type, $genreId, $scope, $seed)->forPage($page, $perPage)->get();
        $view = $type === 'vertical' ? 'frontend.partials.vertical-cards' : 'frontend.partials.feed-cards';

        return response()->json([
            'html' => view($view, ['items' => $items, 'myListIds' => $this->myListIds($profile)])->render(),
            'done' => $items->count() < $perPage,
            'next' => $page + 1,
        ]);
    }

    /**
     * "ดูต่อ / Continue Watching" row, MOST-RECENT first, optionally scoped to a page's type/anime —
     * so on any category page you land straight back on the title you just opened (owner: กดผิดย้อนกลับ
     * มาแล้วหาไม่เจอ). Returns null when nothing is in progress in that scope.
     */
    private function continueRow(Profile $profile, ?string $type = null, ?string $scope = null): ?array
    {
        $ids = $profile->watchProgress()
            ->whereBetween('percent', [1, 94])
            ->orderByDesc('last_watched_at')
            ->limit(20)
            ->pluck('content_id')->all();
        if ($ids === []) {
            return null;
        }

        $q = Content::published()->whereIn('id', $ids)->with(['genres', 'previewEpisode']);
        if ($type) {
            $q->type($type);
        }
        if ($type === 'vertical') {
            $q->withCount('episodes');
        }
        if ($scope === 'notanime') {
            $a = $this->animeGenreIds();
            $q->whereDoesntHave('genres', fn ($g) => $g->whereIn('genres.id', $a));
        } elseif ($scope === 'anime') {
            $a = $this->animeGenreIds();
            $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $a));
        }

        // preserve the last_watched order (whereIn loses it)
        $items = $q->get()->sortBy(fn ($c) => array_search($c->id, $ids, true))->values();
        if ($items->isEmpty()) {
            return null;
        }

        return ['title' => 'ดูต่อสำหรับ '.$profile->name, 'en' => 'Continue Watching', 'genre' => null, 'items' => $items];
    }

    public function series(Request $request): View
    {
        return $this->grouped($request, 'series', 'ซีรี่ส์');
    }

    public function movies(Request $request): View
    {
        return $this->grouped($request, 'movie', 'ภาพยนตร์');
    }

    private function grouped(Request $request, string $type, string $heading): View
    {
        $profile = $this->profile($request);
        $animeIds = $this->animeGenreIds();
        $notAnime = fn ($q) => $q->whereDoesntHave('genres', fn ($g) => $g->whereIn('genres.id', $animeIds));

        $rows = [];
        $rowSeed = random_int(1, 999999);

        // Continue watching first (this type only).
        if ($c = $this->continueRow($profile, $type, 'notanime')) {
            $rows[] = $c;
        }

        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue;
            }
            $items = $this->rowQuery($type, $genre->id, 'notanime', $rowSeed)->take(18)->get();
            if ($items->isNotEmpty()) {
                $rows[] = ['title' => $genre->name, 'en' => $genre->name_en, 'items' => $items,
                    'link' => route('browse.genre', $genre),
                    'lazy' => ['type' => $type, 'genre' => $genre->id, 'scope' => 'notanime', 'seed' => $rowSeed]];
            }
        }

        return view('frontend.browse', [
            'heroSlides' => HeroBillboard::slides($type),   // rotates within this type only
            'heroSeconds' => HeroBillboard::seconds(),
            'heroVideo' => HeroBillboard::videoEnabled(),
            'rows' => $rows,
            'heading' => $heading,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    /** "ดูทั้งหมด" — one genre with a ranking banner, continue-watching row and a sortable grid. */
    public function genre(Request $request, Genre $genre): View
    {
        $profile = $this->profile($request);
        $sort = $request->query('sort', 'random');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $inGenre = fn () => Content::published()
            ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
            ->with(['genres', 'previewEpisode']);

        // Top 3 (ranking banner) + continue-watching within this genre.
        $top = $inGenre()->trending()->take(3)->get();
        $continue = $inGenre()
            ->whereIn('id', $profile->watchProgress()->whereBetween('percent', [1, 94])
                ->orderByDesc('last_watched_at')->pluck('content_id'))
            ->get();

        // Sortable grid — default is a fresh shuffle (like the rest of the site).
        $q = $inGenre();
        if ($sort === 'views') {
            $q->orderBy('views', $dir);
        } elseif ($sort === 'rating') {
            $q->orderBy('rating', $dir);
        } elseif ($sort === 'likes') {
            $q->withCount('likedBy')->orderBy('liked_by_count', $dir);
        } elseif ($sort === 'latest') {
            $q->orderBy('id', $dir);
        } else {
            // Seeded shuffle: stable within a day so the paginator doesn't repeat/skip
            // titles across pages (unseeded inRandomOrder reshuffles every page load).
            $q->inRandomOrder((int) now()->format('Ymd') + $genre->id);
        }

        // Paginate — a big catch-all genre like "ดราม่า" has ~900 titles; rendering them
        // all was a ~5 MB page that OOM'd at memory_limit=128M → intermittent 500.
        return view('frontend.genre', [
            'genre' => $genre,
            'heading' => $genre->name,
            'headingEn' => $genre->name_en,
            'top' => $top,
            'continue' => $continue,
            'items' => $q->paginate(60)->withQueryString(),
            'sort' => $sort,
            'dir' => $dir,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    public function vertical(Request $request): View
    {
        $profile = $this->profile($request);
        $animeIds = $this->animeGenreIds();

        $rows = [];
        $rowSeed = random_int(1, 999999);

        // Continue watching first — jump straight back to the short you just opened.
        if ($c = $this->continueRow($profile, 'vertical')) {
            $rows[] = $c;
        }

        // Trending strip first (curated top-N, not lazy).
        $trending = Content::published()->type('vertical')->trending()
            ->with(['genres', 'previewEpisode'])->withCount('episodes')->take(14)->get();
        if ($trending->isNotEmpty()) {
            $rows[] = ['title' => 'แนวตั้งมาแรง', 'en' => 'Trending Shorts', 'genre' => null, 'items' => $trending];
        }

        // One row per genre — slides through EVERY vertical in that genre (lazy, page 2+ via browse.row).
        $baseCount = count($rows);
        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue;
            }
            $items = $this->rowQuery('vertical', $genre->id, null, $rowSeed)->take(18)->get();
            if ($items->count() >= 2) {
                $rows[] = ['title' => $genre->name, 'genre' => $genre, 'items' => $items,
                    'lazy' => ['type' => 'vertical', 'genre' => $genre->id, 'seed' => $rowSeed]];
            }
        }

        // Nothing grouped (e.g. no genres assigned) → one catch-all row (lazy over all verticals).
        if (count($rows) === $baseCount) {
            $rows[] = [
                'title' => 'ทั้งหมด', 'en' => 'All', 'genre' => null,
                'items' => $this->rowQuery('vertical', null, null, $rowSeed)->take(18)->get(),
                'lazy' => ['type' => 'vertical', 'seed' => $rowSeed],
            ];
        }

        return view('frontend.vertical', [
            'rows' => $rows,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    public function myList(Request $request): View
    {
        $profile = $this->profile($request);

        $items = $profile->myList()->published()->with(['genres', 'previewEpisode'])->orderByDesc('my_list_items.created_at')->get();

        return view('frontend.mylist', [
            'items' => $items,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    private function profile(Request $request): Profile
    {
        return $request->attributes->get('profile');
    }

    /** @return array<int,int> */
    private function myListIds(Profile $profile): array
    {
        return $profile->myList()->pluck('contents.id')->all();
    }
}
