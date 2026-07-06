<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Genre;
use App\Support\HeroBillboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PUBLIC (guest + crawler visible) category hubs — /movies, /series, /anime, /vertical. These rank the
 * head terms ("ดูหนังออนไลน์", "ดูซีรีส์ออนไลน์", …) and give Googlebot strong crawl hubs into the catalog.
 *
 * Deliberately NOT the member BrowseController semantics: the member type pages exclude the "anime"
 * umbrella genre, which empties /movies (every movie is also anime-tagged — see the taxonomy note).
 * An empty hub is a thin page that HURTS SEO, so here each hub lists EVERY public title of its type.
 * Consequence: anime-tagged movies appear on both /movies and /anime — acceptable category overlap.
 */
class PublicCatalogController extends Controller
{
    public function movies(Request $request): View
    {
        return $this->hub($request, 'movie', 'browse.movies', 'ภาพยนตร์', 'Movies',
            'รวมภาพยนตร์ออนไลน์บน NetWix — หนังใหม่ หนังเก่า พากย์ไทยและซับไทย ครบทุกแนว ดูฟรีชัดระดับ HD เล่นได้ทุกอุปกรณ์');
    }

    public function series(Request $request): View
    {
        return $this->hub($request, 'series', 'browse.series', 'ซีรี่ส์', 'Series',
            'รวมซีรีส์ออนไลน์ — ซีรี่ย์เกาหลี จีน ไทย ฝรั่ง พากย์ไทย/ซับไทย อัปเดตตอนใหม่ทุกสัปดาห์ ดูฟรีชัด HD');
    }

    public function vertical(Request $request): View
    {
        return $this->hub($request, 'vertical', 'browse.vertical', 'ซีรีส์แนวตั้ง', 'Shorts',
            'รวมซีรีส์แนวตั้ง โรงหยกและละครสั้นจีน — ดูจบไว ปัดขึ้น–ลงเหมือนโซเชียล ซับไทย/พากย์ไทย ดูฟรี');
    }

    public function anime(Request $request): View
    {
        return $this->hub($request, 'anime', 'browse.anime', 'อนิเมะ / การ์ตูน', 'Anime',
            'รวมอนิเมะและการ์ตูนออนไลน์ — ซับไทยและพากย์ไทย อัปเดตไว ดูฟรีทุกเรื่อง ชัดระดับ HD');
    }

    private function hub(Request $request, string $key, string $routeName, string $heading, string $headingEn, string $intro): View
    {
        $sort = (string) $request->query('sort', 'random');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $top = $this->base($key)->rankedByEngagement()->take(6)->get();

        $q = $this->base($key);
        if ($sort === 'views') {
            $q->orderBy('views', $dir);
        } elseif ($sort === 'rating') {
            $q->orderBy('rating', $dir);
        } elseif ($sort === 'latest') {
            $q->orderBy('id', $dir);
        } else {
            $sort = 'random';
            $q->inRandomOrder((int) now()->format('Ymd') + crc32($key));
        }

        return view('frontend.public.hub', [
            'heading' => $heading,
            'headingEn' => $headingEn,
            'intro' => $intro,
            'routeName' => $routeName,
            'top' => $top,
            'items' => $q->paginate(60)->withQueryString(),
            'sort' => $sort,
            // Rotating billboard scoped to THIS category (cached ~2 min, shared across visitors).
            'heroSlides' => HeroBillboard::slides($key),
            'heroSeconds' => HeroBillboard::seconds(),
            'heroVideo' => HeroBillboard::videoEnabled(),
        ]);
    }

    /** Base query for a hub — all public titles of the type; anime = anything carrying an anime genre. */
    private function base(string $key)
    {
        $q = Content::publicListing()->with('genres');

        if ($key === 'anime') {
            $ids = $this->animeGenreIds();

            return $ids === [] ? $q->whereRaw('1 = 0') : $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $ids));
        }

        return $q->type($key);
    }

    /** @return int[] genre ids for the anime/cartoon umbrella. Cached — it never really changes. */
    private function animeGenreIds(): array
    {
        return cache()->remember('public:anime-genre-ids', 3600,
            fn () => Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all());
    }
}
