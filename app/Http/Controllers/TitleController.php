<?php

namespace App\Http\Controllers;

use App\Models\Content;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TitleController extends Controller
{
    public function show(Request $request, Content $content): View
    {
        abort_unless($content->is_published, 404);

        $content->load(['genres', 'seasons.episodes', 'episodes']);
        $profile = $request->attributes->get('profile');

        // NB: ep1 is NOT pre-mirrored on open anymore — it resolves on demand like every
        // other episode (and like other sites). The mirror system still exists for deliberate
        // admin use (/admin/storage); it's just no longer auto-triggered here.

        $related = Content::published()
            ->whereKeyNot($content->id)
            ->where('type', $content->type)
            ->with('genres')
            ->inRandomOrder()->take(6)->get();

        return view('frontend.title', [
            'content' => $content,
            'related' => $related,
            'inMyList' => $profile->myList()->whereKey($content->id)->exists(),
            'liked' => $profile->likes()->whereKey($content->id)->exists(),
        ]);
    }

    /** Modal partial fetched by the browse cards (AJAX). */
    public function modal(Request $request, Content $content): View
    {
        abort_unless($content->is_published, 404);

        $content->load(['genres', 'seasons.episodes', 'episodes']);
        $profile = $request->attributes->get('profile');

        return view('frontend.partials.title-modal', [
            'content' => $content,
            'inMyList' => $profile->myList()->whereKey($content->id)->exists(),
            'liked' => $profile->likes()->whereKey($content->id)->exists(),
        ]);
    }
}
