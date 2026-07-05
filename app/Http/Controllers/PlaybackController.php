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
        if ($request->boolean('ok')) {
            PlaybackHealth::recordSuccess($content);
        } else {
            PlaybackHealth::recordFailure($content, PlaybackHealth::viewer(), 'player');
        }

        return response()->json(['ok' => true]);
    }
}
