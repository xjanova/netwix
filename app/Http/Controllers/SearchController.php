<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\SearchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PUBLIC search — guests + members. Results are the crawlable public listing (published, non-suspended,
 * non-adult), so adult titles never surface to a signed-out visitor. The results page itself is
 * noindex,follow (thin/near-infinite) but links out to indexable title pages.
 */
class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $results = $q === ''
            ? collect()
            : $this->query($q)->with('genres')->take(60)->get();

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

        return view('frontend.public.search', [
            'q' => $q,
            'results' => $results,
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $results = $q === '' ? collect() : $this->query($q)->take(6)->get(['id', 'title', 'slug', 'year', 'type']);

        return response()->json([
            'results' => $results->map(fn (Content $c) => [
                'title' => $c->title,
                'year' => $c->year,
                'url' => route('title.show', $c),
            ]),
        ]);
    }

    private function query(string $q)
    {
        return Content::publicListing()
            ->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere('synopsis', 'like', "%{$q}%"))
            ->orderByDesc('views');
    }
}
