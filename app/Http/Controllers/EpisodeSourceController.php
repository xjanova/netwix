<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

        // rongyok (and anything else) must be mirrored first — server IP can't fetch it.
        return response()->json([
            'ready' => false,
            'queued' => (bool) $episode->mirror_requested_at,
            'requests' => (int) $episode->mirror_requests,
        ], 202);
    }

    /**
     * A customer opened an episode that isn't mirrored yet — record the request so NetwixSync
     * (running on a residential machine) prioritises it. Marked as customer-triggered.
     */
    public function request(Request $request, Episode $episode): JsonResponse
    {
        if ($episode->video_url) {
            return response()->json(['queued' => false, 'ready' => true]);
        }
        if ($episode->source !== 'rongyok') {
            return response()->json(['queued' => false]); // wow-drama streams live; nothing to queue
        }

        // Debounce repeat views from the same profile within a short window (avoid inflating count).
        $profile = $request->attributes->get('profile');
        $key = "mreq:{$episode->id}:".($profile?->id ?? 'x');
        $fresh = ! Cache::has($key);
        Cache::put($key, 1, now()->addMinutes(30));

        if ($fresh) {
            $episode->increment('mirror_requests');
        }
        if (! $episode->mirror_requested_at) {
            $episode->forceFill(['mirror_requested_at' => now()])->save();
        }

        return response()->json([
            'queued' => true,
            'requests' => (int) $episode->fresh()->mirror_requests,
        ], 202);
    }
}
