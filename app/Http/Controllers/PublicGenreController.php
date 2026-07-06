<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Genre;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PUBLIC (guest + crawler visible) genre hub. Unlike BrowseController::genre — which is personalised
 * and login-gated — this renders a plain, crawlable grid so Google can index the genre page and reach
 * every title in it. Adult titles are excluded via Content::publicListing (guests have no profile, so
 * MaturityScope can't hide them here). Playback is still gated on the title/watch pages themselves.
 *
 * Auth-aware (Phase 3): a signed-in member with an active profile gets the personalised member genre
 * page (BrowseController::genre) at this same URL — same nav/cards/continue-watching as /browse — while
 * guests + crawlers keep the SEO grid below. Same canonical URL, no cloaking (crawler == guest view).
 */
class PublicGenreController extends Controller
{
    public function show(Request $request, Genre $genre): View
    {
        if ($this->activeMemberProfile($request)) {
            return app(BrowseController::class)->genre($request, $genre);
        }

        $sort = (string) $request->query('sort', 'random');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $inGenre = fn () => Content::publicListing()
            ->whereHas('genres', fn ($g) => $g->where('genres.id', $genre->id))
            ->with('genres');

        // Top hits banner — hottest by view count (มาแรง).
        $top = $inGenre()->trending()->take(3)->get();

        $q = $inGenre();
        if ($sort === 'views') {
            $q->orderBy('views', $dir);
        } elseif ($sort === 'rating') {
            $q->orderBy('rating', $dir);
        } elseif ($sort === 'latest') {
            $q->orderBy('id', $dir);
        } else {
            $sort = 'random';
            // Seeded shuffle, stable within a day so the paginator doesn't repeat/skip across pages.
            $q->inRandomOrder((int) now()->format('Ymd') + $genre->id);
        }

        return view('frontend.public.genre', [
            'genre' => $genre,
            'top' => $top,
            'items' => $q->paginate(60)->withQueryString(),
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }
}
