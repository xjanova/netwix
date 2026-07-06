<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Search — guests + members share the URL. A signed-in member (with an active profile) gets the
 * personalised results page (member nav + content-card modal + my-list state), searching their full
 * catalog (Content::published — MaturityScope still hides adult from KIDS profiles). Guests + crawlers
 * get the crawlable public listing (published, non-suspended, non-adult), so adult titles never surface
 * to a signed-out visitor; that results page is noindex,follow (thin/near-infinite) but links out to
 * indexable title pages. Same canonical URL for both, no cloaking (crawler is always session-less).
 */
class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $profile = $this->activeMemberProfile($request);

        // Member → full personalised scope (adult shown to adult profiles, hidden from kids by
        // MaturityScope); guest/crawler → hard non-adult publicListing gate.
        $base = $profile ? Content::published() : Content::publicListing();

        $results = $q === ''
            ? collect()
            : $this->query($q, $base)->with('genres')->take(60)->get();

        // Anonymous content-gap logging: term + result count only, no user linkage (PDPA-safe).
        // Zero-result terms are the import shortlist; see the admin SEO dashboard.
        if ($q !== '' && mb_strlen($q) <= 190) {
            try {
                SearchQuery::create([
                    'term' => mb_strtolower($q, 'UTF-8'),
                    'results' => $results->count(),
                    'is_member' => $request->user() !== null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // logging must never break search
            }
        }

        if ($profile) {
            return view('frontend.search', [
                'q' => $q,
                'results' => $results,
                'myListIds' => $profile->myList()->pluck('contents.id')->all(),
            ]);
        }

        return view('frontend.public.search', [
            'q' => $q,
            'results' => $results,
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        // The type-ahead always uses publicListing — its rows link to route('title.show'), which 404s
        // adult titles, so surfacing adult here (even to an adult member) would dead-link. Members still
        // find adult titles on the full results page above, where cards open the gated in-app modal.
        $results = $q === ''
            ? collect()
            : $this->query($q, Content::publicListing())->take(6)->get(['id', 'title', 'slug', 'year', 'type']);

        return response()->json([
            'results' => $results->map(fn (Content $c) => [
                'title' => $c->title,
                'year' => $c->year,
                'url' => route('title.show', $c),
            ]),
        ]);
    }

    private function query(string $q, Builder $base): Builder
    {
        return $base
            ->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere('synopsis', 'like', "%{$q}%"))
            ->orderByDesc('views');
    }
}
