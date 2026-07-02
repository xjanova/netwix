<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;

class EpisodeSourceController extends Controller
{
    /**
     * Resolve a playable stream for an episode.
     *  - mirrored / manual URL  → ready, play from our storage
     *  - wow-drama (server-resolvable) → ready, via HLS proxy
     *  - rongyok not mirrored   → NOT ready (server can't fetch rongyok); the client should
     *    request a mirror and poll. { ready:false, queued:true }
     */
    public function resolve(Episode $episode, SourceRegistry $registry): JsonResponse
    {
        if ($episode->video_url) {
            return response()->json([
                'ready' => true,
                'kind' => str_contains($episode->video_url, '.m3u8') ? 'hls' : 'mp4',
                'url' => $episode->video_url,
            ]);
        }

        if (! $episode->source || ! $episode->source_ref) {
            return response()->json(['ready' => false, 'error' => 'no_source'], 404);
        }

        // wow-drama plays through the server-side HLS proxy.
        if ($episode->source === 'wowdrama') {
            return response()->json([
                'ready' => true,
                'kind' => 'hls',
                'url' => route('stream.manifest', $episode),
            ]);
        }

        // rongyok not mirrored yet — the server can't fetch it (only our downloader can).
        // The customer just sees "preparing"; admins do the mirroring.
        return response()->json(['ready' => false], 202);
    }
}
