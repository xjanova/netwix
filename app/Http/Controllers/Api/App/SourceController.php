<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EpisodeSourceController;
use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public playable-source resolver for the mobile app. Delegates to the existing
 * EpisodeSourceController@resolve (single source of truth) and re-wraps in the
 * app's {success,data} envelope.
 *
 *   mirrored rongyok → {ready:true, kind:'mp4', url:'https://netwix.online/storage/media/rongyok/{key}/{n}.mp4'}
 *   wow-drama        → {ready:true, kind:'hls', url: <stream manifest>}
 *   not mirrored     → {ready:false} (202) — client shows "preparing"
 */
class SourceController extends Controller
{
    public function source(Request $request, Episode $episode, SourceRegistry $registry, EpisodeSourceController $resolver): JsonResponse
    {
        // The request is passed through so the app gets the same `?refresh=1` escape hatch the web
        // players use: skip a stored copy that won't play and walk the link rotation instead.
        $resp = $resolver->resolve($request, $episode, $registry);
        $status = $resp->getStatusCode();

        return response()->json([
            'success' => $status < 400,
            'data' => $resp->getData(true),
        ], $status);
    }

    /**
     * POST /api/app/episodes/{episode}/cover — "someone is watching this episode and it has no
     * cover". The web player grabs the frame itself off the <canvas> and POSTs the JPEG; the app
     * cannot, because video_player renders into a platform texture that Dart can't read back. So
     * the app reports the moment and the server grabs the frame with ffmpeg — the same fallback
     * the web already uses for no-CORS sources, with the same per-episode lock and hourly budget.
     */
    public function cover(Request $request, Episode $episode, EpisodeSourceController $resolver): JsonResponse
    {
        $resp = $resolver->genCover($request, $episode);
        $status = $resp->getStatusCode();

        return response()->json([
            'success' => $status < 400,
            'data' => $resp->getData(true),
        ], $status);
    }
}
