<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EpisodeSourceController;
use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;

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
    public function source(Episode $episode, SourceRegistry $registry, EpisodeSourceController $resolver): JsonResponse
    {
        $resp = $resolver->resolve($episode, $registry);
        $status = $resp->getStatusCode();

        return response()->json([
            'success' => $status < 400,
            'data' => $resp->getData(true),
        ], $status);
    }
}
