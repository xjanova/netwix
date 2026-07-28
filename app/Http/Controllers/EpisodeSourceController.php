<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\ImageStore;
use App\Support\MirrorRotation;
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

        // Walk the link rotation: a forced backup, then whatever played last, then the title's own
        // source, then every mirror ([App\Support\MirrorRotation]). The link that WINS decides how the
        // stream is played back, which is why this resolves before choosing a response shape — a
        // progressive-source title can perfectly well end up playing from an HLS mirror.
        $resolved = MirrorRotation::resolve($episode, $registry);
        if ($resolved === null) {
            // Every link failed (or is mid-cooldown) — the client shows "preparing" and retries. If the
            // whole cycle failed definitively, MirrorRotation has already unpublished the title.
            if (! $registry->has((string) $episode->source) || ! $episode->content?->source_key) {
                return response()->json(['ready' => false, 'error' => 'no_source'], 404);
            }

            return response()->json(['ready' => false], 202);
        }

        $stream = $resolved['stream'];

        // Embed source (9nung/abyss): playback is a 3rd-party player iframe, not a stream we can proxy.
        // Hand back the embed page for a sandboxed <iframe> in the player (see [EmbedPlayback]).
        if ($stream->kind === RemoteStream::KIND_EMBED) {
            return response()->json(['ready' => true, 'kind' => 'embed', 'url' => $stream->url]);
        }

        // HLS sources (wow-drama / any Halim site / hd432) play through the server-side proxy: it adds
        // the upstream Referer the browser can't send and rewrites the segment URLs. Without this a raw
        // .m3u8 is handed back and the browser can't fetch its Referer-gated segments (web won't play,
        // even though the native app, which sends its own Referer, does). Gate on the resolved KIND so
        // a newly-added source is covered automatically (no per-id whitelist to keep in sync).
        if ($stream->kind === RemoteStream::KIND_HLS) {
            return $this->hlsReady($episode);
        }

        return response()->json(['ready' => true, 'kind' => $stream->kind, 'url' => $stream->url]);
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

    /**
     * Server-side cover fallback: the client calls this when it CAN'T grab a frame in-browser — the
     * source's CDN sends no CORS so the <canvas> is tainted (e.g. anifume/rukoluo). We queue an ffmpeg
     * grab off a small ranged download (EpisodeThumbnailer), which needs no CORS. One in-flight job per
     * episode (5-min lock) so a burst of viewers on the same uncovered episode never stacks duplicates.
     */
    public function genCover(Request $request, Episode $episode): JsonResponse
    {
        abort_unless((bool) $episode->content?->is_published, 404);
        if ($episode->thumbnail_path) {
            return response()->json(['ok' => true, 'skipped' => 'exists']);
        }
        if (Cache::add('episode:gencover:'.$episode->id, 1, now()->addMinutes(5))) {
            \App\Jobs\GenerateEpisodeThumb::dispatch($episode->id)->onQueue('thumbs');
        }

        return response()->json(['ok' => true, 'queued' => true]);
    }
}
