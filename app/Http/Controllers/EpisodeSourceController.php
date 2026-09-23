<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\MirrorRotation;
use App\Support\PlaybackHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
    public function resolve(Request $request, Episode $episode, SourceRegistry $registry): JsonResponse
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

        // A stored copy short-circuits the whole rotation — that IS the point of mirroring. But that
        // also means a bad stored file (wrong storage config, deleted object, expired front door)
        // would take a title off the air with the rotation, the cooldowns and the auto-death checks
        // all bypassed, and the source canary would keep reporting the upstream green the whole time.
        //
        // `?refresh=1` is the escape hatch the player uses after a playback error: it skips the stored
        // copy and walks the rotation instead, so one broken file degrades to live streaming rather
        // than to a dead episode. Costs nothing on the happy path, since it is only ever sent by a
        // client that has already failed once.
        if ($episode->video_url && ! $request->boolean('refresh')) {
            return $this->ready($episode, [
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
            return $this->ready($episode, ['kind' => 'embed', 'url' => $stream->url]);
        }

        // HLS sources (wow-drama / any Halim site / hd432) play through the server-side proxy: it adds
        // the upstream Referer the browser can't send and rewrites the segment URLs. Without this a raw
        // .m3u8 is handed back and the browser can't fetch its Referer-gated segments (web won't play,
        // even though the native app, which sends its own Referer, does). Gate on the resolved KIND so
        // a newly-added source is covered automatically (no per-id whitelist to keep in sync).
        if ($stream->kind === RemoteStream::KIND_HLS) {
            return $this->hlsReady($episode);
        }

        return $this->ready($episode, ['kind' => $stream->kind, 'url' => $stream->url]);
    }

    /**
     * Every "here is something playable" answer goes through here, so the viewer is on record as having
     * been served this title — the proof [PlaybackHealth::wasIssued] asks for before their player's
     * "it didn't play" report may count toward unpublishing it.
     */
    private function ready(Episode $episode, array $payload): JsonResponse
    {
        PlaybackHealth::noteIssued($episode->content);

        return response()->json(['ready' => true] + $payload);
    }

    /**
     * "Ready" response for an HLS episode: hand back the proxied manifest URL with a short-lived token
     * so only this authenticated resolve can mint a playable manifest — see StreamController::manifest.
     */
    private function hlsReady(Episode $episode): JsonResponse
    {
        // Build the playlist the moment its URL goes out. The player still has to load its script and
        // send the request across Cloudflare; by then the upstream fetch (0.5–2s cold, measured
        // 2026-09-23) is already under way, and the player's request waits on the build lock instead
        // of starting a second fetch. Runs after the response is sent, so the viewer never waits on it.
        app()->terminating(function () use ($episode) {
            try {
                app(StreamController::class)->topLevelPlaylist($episode, app(SourceRegistry::class));
            } catch (\Throwable) {
                // best-effort: the player's own request reports and retries exactly as it always has
            }
        });

        return $this->ready($episode, [
            'kind' => 'hls',
            'url' => route('stream.manifest', $episode).'?t='.StreamController::token($episode),
        ]);
    }

    /**
     * Kept for players still running the old script, which upload the frame they grabbed. That frame
     * used to become the episode's cover for everyone — first upload wins, never replaced — so any
     * signed-in account could plant any picture (porn, a scam QR code) on every uncovered episode by
     * walking ids. What they send is now ignored: the request is treated as "this episode is playing
     * and has no cover", and the server takes the frame from the stream itself (genCover).
     */
    public function captureThumb(Request $request, Episode $episode): JsonResponse
    {
        return $this->genCover($request, $episode);
    }

    /**
     * Server-side cover fallback: the caller can't grab the frame itself. Two callers now —
     *  - the web player, when the source's CDN sends no CORS so the <canvas> is tainted (anifume/rukoluo);
     *  - the mobile app, always: video_player draws into a platform texture, and a texture is not
     *    readable from Dart, so the app has no frame to send in the first place.
     * We queue an ffmpeg grab off a small ranged download (EpisodeThumbnailer), which needs no CORS.
     * One in-flight job per episode (5-min lock) so a burst of viewers on the same uncovered episode
     * never stacks duplicates, plus an hourly ceiling across ALL episodes — see below.
     */
    public function genCover(Request $request, Episode $episode): JsonResponse
    {
        abort_unless((bool) $episode->content?->is_published, 404);
        if ($episode->thumbnail_path) {
            return response()->json(['ok' => true, 'skipped' => 'exists']);
        }
        if (! Cache::add('episode:gencover:'.$episode->id, 1, now()->addMinutes(5))) {
            return response()->json(['ok' => true, 'queued' => false, 'status' => 'in_flight']);
        }
        // Hourly ceiling across every episode. The per-episode lock stops a crowd on ONE title; it
        // does nothing about a client walking episode ids, and each job is an ffmpeg — the exact
        // shape of the 2026-07-06 crash (stacked ffmpeg workers, not disk). Past the ceiling we drop
        // the request instead of queueing: an uncovered episode still gets its cover from the admin
        // batch, or from the next hour's viewers. The per-episode lock is released so this episode
        // isn't ALSO blocked for 5 minutes by a request we deliberately didn't run.
        if (! self::withinGenCoverBudget()) {
            Cache::forget('episode:gencover:'.$episode->id);

            return response()->json(['ok' => true, 'queued' => false, 'status' => 'busy']);
        }
        \App\Jobs\GenerateEpisodeThumb::dispatch($episode->id)->onQueue('thumbs');

        return response()->json(['ok' => true, 'queued' => true]);
    }

    /** How many on-demand ffmpeg cover grabs may be queued site-wide in one hour. */
    private const GENCOVER_PER_HOUR = 120;

    /**
     * True while this hour's on-demand cover budget still has room (and books one slot).
     * Counter, not a rate limiter: it is deliberately shared by every viewer and both clients,
     * because the resource being protected (the ffmpeg queue) is shared too.
     */
    private static function withinGenCoverBudget(): bool
    {
        $key = 'episode:gencover:hour:'.now()->format('YmdH');
        Cache::add($key, 0, now()->addHour());

        return (int) Cache::increment($key) <= self::GENCOVER_PER_HOUR;
    }
}
