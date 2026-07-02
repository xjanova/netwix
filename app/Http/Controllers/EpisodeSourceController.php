<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class EpisodeSourceController extends Controller
{
    /**
     * Resolve a playable stream for an episode. Imported episodes are resolved live (remote URLs
     * expire) and returned as URLs that go through our streaming proxy, so the browser plays them
     * from our origin (no CORS / referer / fake-header problems).
     */
    public function resolve(Episode $episode, SourceRegistry $registry): JsonResponse
    {
        // Manually-entered URL — hand it back directly.
        if (! $episode->source || ! $episode->source_ref) {
            return $episode->video_url
                ? response()->json(['kind' => str_contains($episode->video_url, '.m3u8') ? 'hls' : 'mp4', 'url' => $episode->video_url])
                : response()->json(['error' => 'no_source'], 404);
        }

        $source = $registry->get($episode->source);
        if (! $source) {
            return response()->json(['error' => 'unknown_source'], 422);
        }

        $key = $episode->content->source_key ?? '';

        $raw = Cache::remember("ep_raw:{$episode->id}", now()->addMinutes(10), function () use ($source, $key, $episode) {
            $s = $source->resolveByRef($key, $episode->source_ref);

            return $s ? ['kind' => $s->kind, 'url' => $s->url, 'referer' => $s->referer] : null;
        });

        if (! $raw) {
            Cache::forget("ep_raw:{$episode->id}");

            return response()->json(['error' => 'resolve_failed'], 502);
        }

        $url = $raw['kind'] === 'hls'
            ? route('stream.manifest', $episode)
            : route('stream.mp4', $episode);

        return response()->json(['kind' => $raw['kind'], 'url' => $url]);
    }
}
