<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\SourceRegistry;
use App\Support\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class EpisodeSourceController extends Controller
{
    /**
     * Resolve a playable stream for an episode.
     *  - stored URL (manual / preview ep1) → ready, play it directly
     *  - wow-drama                         → ready, via the server-side HLS proxy
     *  - rongyok (or any signed source)    → ready, resolve a FRESH signed CDN url on demand and
     *    cache it per-episode until just before it expires. NetWix does this itself now — the
     *    home downloader is no longer required.
     */
    public function resolve(Episode $episode, SourceRegistry $registry): JsonResponse
    {
        // Never hand out a playable URL for unpublished/embargoed content — the public mobile
        // endpoint (/api/app/…) shares this resolver, and the rest of the app gates on this too.
        abort_unless((bool) $episode->content?->is_published, 404);

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

        // wow-drama & anime108 play through the server-side HLS proxy.
        if (in_array($episode->source, ['wowdrama', 'anime108'], true)) {
            return response()->json([
                'ready' => true,
                'kind' => 'hls',
                'url' => route('stream.manifest', $episode),
            ]);
        }

        // Signed-CDN sources (rongyok): the URL expires ~24h, so resolve on demand and cache the
        // resolved url per-episode until shortly before it expires.
        $cacheKey = "episode:src:{$episode->id}";
        if (is_string($cached = Cache::get($cacheKey)) && $cached !== '') {
            return response()->json(['ready' => true, 'kind' => 'mp4', 'url' => $cached]);
        }

        // A recent failure is cached briefly so a burst of "preparing" polls doesn't hammer the
        // upstream (and get NetWix's IP blocked) while it's momentarily unavailable.
        if (Cache::get($cacheKey.':miss')) {
            return response()->json(['ready' => false], 202);
        }

        $source = $registry->get($episode->source);
        $seriesKey = $episode->content?->source_key;
        if (! $source || ! $seriesKey) {
            return response()->json(['ready' => false, 'error' => 'no_source'], 404);
        }

        $stream = $source->resolveByRef((string) $seriesKey, (string) $episode->source_ref);
        if (! $stream) {
            // Transient (source down / just rotated again) — the client shows "preparing" and retries.
            Cache::put($cacheKey.':miss', 1, now()->addSeconds(15));

            return response()->json(['ready' => false], 202);
        }

        // Cache until ~1h before the signed url's own expiry (ex=<hex unix seconds>), min 60s.
        $ttl = 6 * 3600;
        if (preg_match('~[?&]ex=([0-9a-f]+)~i', $stream->url, $m)) {
            $ttl = max(60, (int) hexdec($m[1]) - time() - 3600);
        }
        Cache::put($cacheKey, $stream->url, now()->addSeconds($ttl));

        return response()->json(['ready' => true, 'kind' => $stream->kind, 'url' => $stream->url]);
    }

    /**
     * Store a small JPEG frame grabbed from the player as this episode's cover — first capture wins
     * (never overwritten), so an episode gets a real thumbnail the first time anyone watches it and
     * falls back to the title's main poster until then. Only works for same-origin video (our HLS
     * proxy / stored mp4); a cross-origin source taints the canvas client-side and just isn't sent.
     */
    public function captureThumb(Request $request, Episode $episode): JsonResponse
    {
        abort_unless((bool) $episode->content?->is_published, 404);

        if ($episode->thumbnail_path) {
            return response()->json(['ok' => true, 'skipped' => 'exists']);
        }

        $data = $request->validate(['image' => ['required', 'string', 'max:600000']]);
        $bin = ImageStore::decodeDataUrl($data['image'], 600_000);
        if ($bin === null) {
            return response()->json(['ok' => false, 'error' => 'invalid'], 422);
        }
        $path = ImageStore::putWebp($bin, 'media/thumbs', (string) $episode->id, 640);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'decode'], 422);
        }
        $episode->update(['thumbnail_path' => $path]);

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($path)]);
    }
}
