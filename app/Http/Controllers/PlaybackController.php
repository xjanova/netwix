<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Support\PlaybackHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackController extends Controller
{
    /**
     * The browser player pings this: ok=false when playback fatally failed (dead source / retries
     * exhausted), ok=true once it actually starts playing. Drives PlaybackHealth's auto-suspend so a
     * title only a handful of real viewers can't watch gets pulled for admin review.
     */
    public function report(Request $request, Content $content): JsonResponse
    {
        // Only a viewer we actually handed a stream for this title gets a vote. The endpoint is public
        // (guests watch too), and five unproven "it didn't play" posts used to unpublish any title.
        // Unproven reports are dropped quietly — the answer is the same either way.
        $viewer = PlaybackHealth::viewer();
        if (! PlaybackHealth::wasIssued($content, $viewer)) {
            return response()->json(['ok' => true]);
        }

        if ($request->boolean('ok')) {
            PlaybackHealth::recordSuccess($content);
        } else {
            PlaybackHealth::recordFailure($content, $viewer, 'player');
        }

        return response()->json(['ok' => true]);
    }
}
