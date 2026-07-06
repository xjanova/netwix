<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\Contracts\EmbedPlayback;
use App\Services\Import\RemoteStream;
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
        // ($episode->content is null for a kids profile on an adult title — the global scope hides it.)
        abort_unless((bool) $episode->content?->is_published, 404);

        // Adult (18+/20+) titles are Pro-only — don't resolve a stream for a non-Pro web viewer.
        if ($episode->content->requires_pro && ! auth()->user()?->isProMember()) {
            return response()->json(['ready' => false, 'error' => 'pro_required'], 403);
        }

        // VIP zone: gold-unlock (or Pro) required. Fails closed for guests/app (no viewer → locked),
        // so a stream is never handed out for a VIP title without a member who's paid for it.
        if ($episode->content->is_vip) {
            $viewer = auth()->user();
            $access = $viewer ? app(\App\Services\GoldWallet::class)->vipAccess($viewer, $episode->content) : 'locked';
            if ($access === 'locked') {
                return response()->json(['ready' => false, 'error' => 'vip_required'], 403);
            }
        }

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

        // A manually FORCED backup (admin "บังคับอัพเดทลิ้งค์") dictates playback — including its KIND —
        // over the primary. Every backup-pool site is HLS, so a forced backup means "play via the proxy"
        // even if the primary is a signed-CDN (progressive) source. StreamController::resolve then picks
        // the forced backup's stream first.
        if ($episode->backup_forced && $episode->backup_source) {
            $forced = $registry->get($episode->backup_source);
            if ($forced && ! $forced->isProgressive()) {
                return $this->hlsReady($episode);
            }
        }

        // HLS sources (wow-drama / any Halim site) play through the server-side proxy: it adds the
        // upstream Referer the browser can't send and rewrites the segment URLs. Without this a raw
        // .m3u8 is handed back and the browser can't fetch its Referer-gated segments (web won't play,
        // even though the native app, which sends its own Referer, does). Gate on !isProgressive() so a
        // newly-added Halim source is covered automatically (no per-id whitelist to keep in sync).
        $primary = $registry->get($episode->source);

        // Embed source (9nung/abyss): playback is a 3rd-party player iframe, not a stream we can proxy.
        // Hand back the embed page for a sandboxed <iframe> in the player (see [EmbedPlayback]).
        if ($primary instanceof EmbedPlayback) {
            return $this->embedReady($episode, $primary);
        }

        if ($primary && ! $primary->isProgressive()) {
            return $this->hlsReady($episode);
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

        $seriesKey = $episode->content?->source_key;
        if (! $primary || ! $seriesKey) {
            return response()->json(['ready' => false, 'error' => 'no_source'], 404);
        }

        $stream = $primary->resolveByRef((string) $seriesKey, (string) $episode->source_ref);
        if (! $stream) {
            // Primary CDN link is dead — if the bot found an HLS backup on another Halim site, play it
            // through the proxy instead (same fallback the StreamController resolve applies).
            $backup = $episode->backup_source ? $registry->get($episode->backup_source) : null;
            if ($backup && ! $backup->isProgressive()) {
                return $this->hlsReady($episode);
            }

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
     * "Ready" response for an EMBED episode (9nung/abyss): resolve the 3rd-party embed page once and
     * cache it briefly (the abyss id lives on the source's detail page, which we don't want to scrape on
     * every poll). The front-end renders it in a sandboxed <iframe> — popups blocked, no proxy.
     */
    private function embedReady(Episode $episode, EmbedPlayback $source): JsonResponse
    {
        $cacheKey = "episode:embed:{$episode->id}";
        $url = Cache::get($cacheKey);

        if (! is_string($url) || $url === '') {
            $seriesKey = (string) ($episode->content?->source_key ?? '');
            $stream = $seriesKey !== '' ? $source->resolveByRef($seriesKey, (string) $episode->source_ref) : null;
            if (! $stream || $stream->kind !== RemoteStream::KIND_EMBED || $stream->url === '') {
                // Detail page didn't yield an embed id (title may have no real stream) — client shows "preparing".
                Cache::put($cacheKey.':miss', 1, now()->addSeconds(20));

                return response()->json(['ready' => false], 202);
            }
            $url = $stream->url;
            Cache::put($cacheKey, $url, now()->addMinutes(30));
        }

        return response()->json(['ready' => true, 'kind' => 'embed', 'url' => $url]);
    }

    /**
     * "Ready" response for an HLS episode: hand back the proxied manifest URL with a short-lived token
     * so only this authenticated resolve can mint a playable manifest — see StreamController::manifest.
     */
    private function hlsReady(Episode $episode): JsonResponse
    {
        return response()->json([
            'ready' => true,
            'kind' => 'hls',
            'url' => route('stream.manifest', $episode).'?t='.StreamController::token($episode),
        ]);
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
        $path = ImageStore::putCover($bin, 'media/thumbs', (string) $episode->id, $episode->thumbnail_path, 640);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'decode'], 422);
        }
        $episode->update(['thumbnail_path' => $path]);

        return response()->json(['ok' => true, 'url' => Storage::disk('public')->url($path)]);
    }
}
