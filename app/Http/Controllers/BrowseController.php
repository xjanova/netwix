<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Genre;
use App\Models\Profile;
use App\Services\Recommender;
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

        // Hero is random (not "the newest"), and prefers a title that actually has a video to play
        // (YouTube trailer or a stored ep-1 preview) so the hero background isn't a still image.
        $hero = Content::published()->where('is_featured', true)->where($notAnime)
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->where($notAnime)
                ->where(fn ($q) => $q->whereNotNull('trailer_youtube_id')->orWhereHas('previewEpisode'))
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->where($notAnime)
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first();

        $rows = [];

        // Continue watching
        $continue = Content::published()
            ->whereIn('id', $profile->watchProgress()
                ->whereBetween('percent', [1, 94])
                ->orderByDesc('last_watched_at')
                ->pluck('content_id'))
            ->with(['genres', 'previewEpisode'])->get();
        if ($continue->isNotEmpty()) {
            $rows[] = ['title' => 'ดูต่อสำหรับ '.$profile->name, 'items' => $continue];
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
            'ranked' => true,
            'items' => Content::published()->rankedByEngagement()->with(['genres', 'previewEpisode'])->take(10)->get(),
        ];

        // My list
        $myList = $profile->myList()->published()->with(['genres', 'previewEpisode'])->get();
        if ($myList->isNotEmpty()) {
            $rows[] = ['title' => 'รายการของฉัน', 'items' => $myList];
        }

        // Per-genre rows — anime/cartoon lives on its own /anime page, so it's kept out here
        // (both the umbrella rows and anime titles bleeding into other genre rows), and shuffled.
        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue;
            }
            $items = Content::published()->where($notAnime)
                ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->take(14)->get();
            if ($items->count() >= 3) {
                $rows[] = ['title' => $genre->name, 'items' => $items, 'link' => route('browse.genre', $genre)];
            }
        }

        return view('frontend.browse', [
            'hero' => $hero,
            'heroResolveUrl' => $this->heroResolveUrl($hero),
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

        $hero = Content::published()->where($isAnime)->where('is_featured', true)
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->where($isAnime)
                ->where(fn ($q) => $q->whereNotNull('trailer_youtube_id')->orWhereHas('previewEpisode'))
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->where($isAnime)->with(['genres', 'previewEpisode'])->inRandomOrder()->first();

        $rows = [[
            'title' => 'อนิเมะมาแรง', 'ranked' => true,
            'items' => Content::published()->where($isAnime)->rankedByEngagement()
                ->with(['genres', 'previewEpisode'])->take(10)->get(),
        ]];

        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue; // skip the umbrella genres — group by the real sub-genres instead
            }
            $items = Content::published()->where($isAnime)
                ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->take(14)->get();
            if ($items->count() >= 3) {
                $rows[] = ['title' => $genre->name, 'items' => $items, 'link' => route('browse.genre', $genre)];
            }
        }

        if (count($rows) <= 1) { // no sub-genre rows filled → one catch-all row
            $rows[] = [
                'title' => 'อนิเมะทั้งหมด',
                'items' => Content::published()->where($isAnime)->with(['genres', 'previewEpisode'])
                    ->inRandomOrder()->take(24)->get(),
            ];
        }

        return view('frontend.browse', [
            'hero' => $hero, 'heroResolveUrl' => $this->heroResolveUrl($hero),
            'rows' => $rows, 'heading' => 'อนิเมะ / การ์ตูน',
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    /** @return int[] genre ids for the anime/cartoon section, kept off the main browse. */
    private function animeGenreIds(): array
    {
        return Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
    }

    /**
     * On-demand resolve URL for the hero's first episode, so the hero can stream a live preview
     * (HLS anime108/wow-drama, or a signed rongyok mp4) muted in the background — no file stored.
     * Null when the hero has a stored preview clip (used directly) or no playable episode.
     */
    private function heroResolveUrl(?Content $hero): ?string
    {
        if (! $hero || $hero->preview_url) {
            return null;   // youtube/backdrop handled in the view; stored clip plays directly
        }
        $ep = $hero->episodes()->orderBy('season_id')->orderBy('sort')->orderBy('number')->first();

        return ($ep && $ep->source) ? route('episode.source', $ep) : null;
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

        $hero = Content::published()->type($type)->where('is_featured', true)->where($notAnime)
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->type($type)->where($notAnime)
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->first();

        $rows = [];
        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue;
            }
            $items = Content::published()->type($type)->where($notAnime)
                ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
                ->with(['genres', 'previewEpisode'])->inRandomOrder()->take(14)->get();
            if ($items->isNotEmpty()) {
                $rows[] = ['title' => $genre->name, 'items' => $items, 'link' => route('browse.genre', $genre)];
            }
        }

        return view('frontend.browse', [
            'hero' => $hero,
            'heroResolveUrl' => $this->heroResolveUrl($hero),
            'rows' => $rows,
            'heading' => $heading,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    /** "ดูทั้งหมด" — every published title in one genre (grid), reached from a genre row heading. */
    public function genre(Request $request, Genre $genre): View
    {
        $profile = $this->profile($request);

        $items = Content::published()
            ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
            ->with(['genres', 'previewEpisode'])
            ->orderByDesc('views')->orderByDesc('id')->get();

        return view('frontend.genre', [
            'heading' => $genre->name,
            'items' => $items,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    public function vertical(Request $request): View
    {
        $profile = $this->profile($request);
        $animeIds = $this->animeGenreIds();

        $rows = [];

        // Trending strip first.
        $trending = Content::published()->type('vertical')->rankedByEngagement()
            ->with(['genres', 'previewEpisode'])->withCount('episodes')->take(14)->get();
        if ($trending->isNotEmpty()) {
            $rows[] = ['title' => 'แนวตั้งมาแรง', 'genre' => null, 'items' => $trending];
        }

        // One shuffled row per genre.
        foreach (Genre::orderBy('sort')->get() as $genre) {
            if (in_array($genre->id, $animeIds, true)) {
                continue;
            }
            $items = Content::published()->type('vertical')
                ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
                ->with(['genres', 'previewEpisode'])->withCount('episodes')->inRandomOrder()->take(14)->get();
            if ($items->count() >= 2) {
                $rows[] = ['title' => $genre->name, 'genre' => $genre, 'items' => $items];
            }
        }

        // Nothing grouped (e.g. no genres assigned) → one catch-all row.
        if ($rows === [] || count($rows) === 1) {
            $rows[] = [
                'title' => 'ทั้งหมด', 'genre' => null,
                'items' => Content::published()->type('vertical')
                    ->with(['genres', 'previewEpisode'])->withCount('episodes')->latest()->take(30)->get(),
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
