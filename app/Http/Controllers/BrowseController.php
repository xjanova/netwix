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

        $hero = Content::published()->where('is_featured', true)
            ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->with(['genres', 'previewEpisode'])->latest()->first();

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

        // Per-genre rows
        foreach (Genre::orderBy('sort')->get() as $genre) {
            $items = Content::published()
                ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
                ->with(['genres', 'previewEpisode'])->latest()->take(14)->get();
            if ($items->count() >= 3) {
                $rows[] = ['title' => $genre->name, 'items' => $items];
            }
        }

        return view('frontend.browse', [
            'hero' => $hero,
            'rows' => $rows,
            'myListIds' => $this->myListIds($profile),
            'feedSeed' => random_int(1, 999999),
            'feedGenres' => Genre::orderBy('sort')->get(['id', 'name']),
        ]);
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

        $hero = Content::published()->type($type)->where('is_featured', true)
            ->with(['genres', 'previewEpisode'])->inRandomOrder()->first()
            ?? Content::published()->type($type)->with(['genres', 'previewEpisode'])->latest()->first();

        $rows = [];
        foreach (Genre::orderBy('sort')->get() as $genre) {
            $items = Content::published()->type($type)
                ->whereHas('genres', fn ($q) => $q->where('genres.id', $genre->id))
                ->with(['genres', 'previewEpisode'])->latest()->take(14)->get();
            if ($items->isNotEmpty()) {
                $rows[] = ['title' => $genre->name, 'items' => $items];
            }
        }

        return view('frontend.browse', [
            'hero' => $hero,
            'rows' => $rows,
            'heading' => $heading,
            'myListIds' => $this->myListIds($profile),
        ]);
    }

    public function vertical(Request $request): View
    {
        $profile = $this->profile($request);

        $items = Content::published()->type('vertical')
            ->with(['genres', 'previewEpisode'])->withCount('episodes')->latest()->get();

        return view('frontend.vertical', [
            'items' => $items,
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
