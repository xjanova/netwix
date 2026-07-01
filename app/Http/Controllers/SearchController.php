<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $profile = $request->attributes->get('profile');

        $results = $q === ''
            ? collect()
            : $this->query($q)->with('genres')->take(60)->get();

        return view('frontend.search', [
            'q' => $q,
            'results' => $results,
            'myListIds' => $profile instanceof Profile ? $profile->myList()->pluck('contents.id')->all() : [],
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
        return Content::published()
            ->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere('synopsis', 'like', "%{$q}%"))
            ->orderByDesc('views');
    }
}
