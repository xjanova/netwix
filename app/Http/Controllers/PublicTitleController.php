<?php

namespace App\Http\Controllers;

use App\Models\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * PUBLIC (guest + crawler visible) title detail page — the core SEO surface. Shows synopsis, poster,
 * cast of episodes, rating, trailer and rich JSON-LD, but keeps PLAYBACK login-gated (the "เล่น"
 * button routes to /login for guests). Adult (18+/20+) titles are never served here: guests have no
 * profile so MaturityScope can't protect minors, and we don't want adult content in the public index.
 */
class PublicTitleController extends Controller
{
    public function show(Request $request, Content $content): View|RedirectResponse
    {
        abort_unless($content->is_published, 404);
        abort_if($content->suspended_at !== null, 404);

        // Adults-only content is not part of the public/crawlable surface at all — 404 it here. It
        // stays reachable to adult members through the in-app modal (which applies the Pro + maturity
        // gates). This keeps 18+/20+ titles out of Google's index and away from minors browsing as a guest.
        abort_if($content->is_adult, 404);

        $content->load(['genres', 'seasons.episodes', 'episodes', 'ratings']);

        // NOTE: unlike the member title page we do NOT warm the ep1 preview here — a crawler sweeping
        // the whole catalog would otherwise enqueue a download job per title. Members warm previews via
        // the in-app modal; guests still get the stored clip / backdrop / poster.

        $related = Content::publicListing()
            ->whereKeyNot($content->id)
            ->where('type', $content->type)
            ->with('genres')
            ->inRandomOrder()->take(12)->get();

        // Interaction state is personalised to a profile, which the public route doesn't resolve —
        // so the list/like buttons start neutral and (for members) self-correct on first click.
        return view('frontend.public.title', [
            'content' => $content,
            'related' => $related,
            'inMyList' => false,
            'liked' => false,
        ]);
    }
}
