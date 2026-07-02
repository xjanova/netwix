<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class EpisodeSourceController extends Controller
{
    /**
     * Resolve a fresh playable stream for an imported episode. Remote URLs (Discord CDN /
     * getplay-cdn) are signed and expire, so we resolve on demand and cache briefly.
     */
    public function resolve(Episode $episode, SourceRegistry $registry): JsonResponse
    {
        // Non-imported episode: just hand back its stored URL.
        if (! $episode->source || ! $episode->source_ref) {
            return $episode->video_url
                ? response()->json(['kind' => str_contains($episode->video_url, '.m3u8') ? 'hls' : 'mp4', 'url' => $episode->video_url])
                : response()->json(['error' => 'no_source'], 404);
        }

        $source = $registry->get($episode->source);
        if (! $source) {
            return response()->json(['error' => 'unknown_source'], 422);
        }

        $sourceKey = $episode->content->source_key ?? '';

        $data = Cache::remember("ep_stream:{$episode->id}", now()->addMinutes(10), function () use ($source, $sourceKey, $episode) {
            $stream = $source->resolveByRef($sourceKey, $episode->source_ref);

            return $stream ? ['kind' => $stream->kind, 'url' => $stream->url, 'referer' => $stream->referer] : null;
        });

        if (! $data) {
            Cache::forget("ep_stream:{$episode->id}");

            return response()->json(['error' => 'resolve_failed'], 502);
        }

        return response()->json($data);
    }
}
