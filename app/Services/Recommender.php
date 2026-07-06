<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Personalised "แนะนำสำหรับคุณ" feed. Recommendations lean on two signals (owner: หนังแนะนำดูจาก
 * ดาวคะแนน กับนิสัยการดูของคนคนนั้น): (1) the profile's VIEWING HABITS — its favourite genres learnt
 * from what it watches / likes / saves — and (2) the title's STAR RATING (higher-rated float up).
 * A seeded id-spread still mixes in the rest for discovery, and keeps infinite-scroll pages stable
 * (no repeats/gaps) with even coverage across the (large) catalogue.
 */
class Recommender
{
    /** How strongly a preferred-genre (viewing-habit) title is boosted vs the random spread. */
    private const PREF_BOOST = 400;

    /** How much a title's star rating (0-10) lifts it in the feed — × this per rating point. */
    private const RATING_WEIGHT = 120;

    /** Odd LCG-style multiplier → good id spread; the seed shifts the whole sequence. */
    private const SPREAD_MULT = 1103515245;

    /** Top genres this profile engages with, most-engaged first. */
    public function affinityGenreIds(Profile $profile, int $limit = 6): array
    {
        $ids = $profile->watchProgress()->pluck('content_id')
            ->concat($profile->likes()->pluck('contents.id'))
            ->concat($profile->myList()->pluck('contents.id'))
            ->unique()->filter()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('content_genre')
            ->whereIn('content_id', $ids)
            ->select('genre_id', DB::raw('count(*) as c'))
            ->groupBy('genre_id')->orderByDesc('c')->limit($limit)
            ->pluck('genre_id')->map(fn ($g) => (int) $g)->all();
    }

    /**
     * The personalised feed query. `$seed` keeps the order stable across scroll
     * pages; `$genreId` (optional) pins the feed to one category. Preferred-genre
     * titles float up but the random spread still surfaces everything.
     */
    public function feedQuery(Profile $profile, int $seed, ?int $genreId = null): Builder
    {
        // When a category is pinned, don't also skew by affinity — the user asked for that genre.
        $pref = $genreId ? [] : $this->affinityGenreIds($profile);
        $prefList = $pref ? implode(',', array_map('intval', $pref)) : null;

        $boost = $prefList
            ? "(CASE WHEN EXISTS(select 1 from content_genre cg where cg.content_id = contents.id and cg.genre_id in ($prefList)) THEN 1 ELSE 0 END)"
            : '0';

        $seed = abs($seed) % 1000000;
        $mult = self::SPREAD_MULT;
        $boostScale = self::PREF_BOOST;
        $ratingWeight = self::RATING_WEIGHT;

        // Rank = viewing-habit boost + star rating + a seeded discovery spread.
        return Content::published()
            ->when($genreId, fn ($q) => $q->whereHas('genres', fn ($g) => $g->where('genres.id', $genreId)))
            ->with(['genres', 'previewEpisode'])
            ->orderByRaw("($boost * $boostScale + COALESCE(contents.rating, 0) * $ratingWeight + ((contents.id * $mult + $seed) % 1000)) desc");
    }
}
