<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WatchController extends Controller
{
    public function show(Request $request, Content $content, ?Episode $episode = null): View
    {
        abort_unless($content->is_published, 404);

        $content->load(['episodes' => fn ($q) => $q->orderBy('season_id')->orderBy('number')]);

        // Vertical short-drama gets its own swipeable player.
        if ($content->type === 'vertical') {
            return view('frontend.vertical-player', [
                'content' => $content,
                'episodes' => $content->episodes,
                'start' => (int) $request->query('ep', 0),
            ]);
        }

        // Resolve the episode to play: explicit, else first.
        if ($episode) {
            abort_unless($episode->content_id === $content->id, 404);
        } else {
            $episode = $content->episodes->first();
        }

        $source = $episode?->video_url ?? $content->video_url;

        // Imported episodes carry a source ref instead of a stored URL — resolve it on demand.
        $resolveUrl = ($episode && $episode->source && ! $source)
            ? route('episode.source', $episode)
            : null;

        return view('frontend.watch', [
            'content' => $content,
            'episode' => $episode,
            'source' => $source,
            'resolveUrl' => $resolveUrl,
            'youtubeId' => Content::youtubeIdFrom($source) ?? $content->youtube_id,
        ]);
    }
}
