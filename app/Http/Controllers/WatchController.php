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

        // Episode to start on: explicit, else the first. The player itself lists every episode and
        // advances through them client-side, so we just pick the starting index here.
        if ($episode) {
            abort_unless($episode->content_id === $content->id, 404);
        } else {
            $episode = $content->episodes->first();
        }
        $startIndex = $episode ? $content->episodes->search(fn ($e) => $e->id === $episode->id) : 0;

        $source = $episode?->video_url ?? $content->video_url;

        return view('frontend.watch', [
            'content' => $content,
            'episode' => $episode,
            'episodes' => $content->episodes,
            'startIndex' => $startIndex === false ? 0 : (int) $startIndex,
            'youtubeId' => Content::youtubeIdFrom($source) ?? $content->youtube_id,
        ]);
    }
}
