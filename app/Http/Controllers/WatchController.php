<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\Content;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class WatchController extends Controller
{
    public function show(Request $request, Content $content, ?Episode $episode = null): View|RedirectResponse
    {
        abort_unless($content->is_published, 404);

        // Adult (18+/20+) titles need Pro. Kids profiles never get here — the maturity global scope
        // 404s the route binding for them; an adult profile without Pro sees the upgrade wall instead.
        // Guests (the page is open to them now) get bounced to sign in first — fail-closed.
        if ($content->requires_pro && ! $request->user()?->isProMember()) {
            if (! $request->user()) {
                return redirect()->guest(route('login'));
            }

            return view('frontend.locked-pro', ['content' => $content]);
        }

        // VIP zone: needs a gold-unlock (or Pro). Fail-closed — same web-auth model as the adult gate.
        if ($content->is_vip) {
            if (! $request->user()) {
                return redirect()->guest(route('login'));
            }
            $gold = app(\App\Services\GoldWallet::class);
            if ($gold->vipAccess($request->user(), $content) === 'locked') {
                return view('frontend.locked-vip', [
                    'content' => $content,
                    'cost' => $gold->vipCost($content),
                    'gold' => (int) $request->user()->gold_coins,
                ]);
            }
        }

        // Count the watch (deduped per viewer + title for 6h; same key as the app).
        // views = the all-time total; views_web = the web slice of the app/web split.
        $vkey = 'view:'.$content->id.':'.sha1((string) $request->ip());
        if (Cache::add($vkey, 1, now()->addHours(6))) {
            $content->increment('views');
            $content->increment('views_web');
        }

        $content->load(['episodes' => fn ($q) => $q->orderBy('season_id')->orderBy('number')]);
        $content->loadMissing('genres');   // for pre-roll genre targeting

        // Pre-roll ad to play before the video starts (null when none is eligible / Pro-hidden / off).
        $ad = $this->preroll($content, $request->user());

        // Vertical short-drama gets its own swipeable player.
        if ($content->type === 'vertical') {
            return view('frontend.vertical-player', [
                'content' => $content,
                'episodes' => $content->episodes,
                'start' => (int) $request->query('ep', 0),
                'ad' => $ad,
            ]);
        }

        // Episode to start on: explicit, else the first. The player itself lists every episode and
        // advances through them client-side, so we just pick the starting index here.
        if ($episode) {
            abort_unless($episode->content_id === $content->id, 404);
        } else {
            $episode = $content->episodes->first();
        }
        $startIndex = $episode ? $content->episodes->search(fn ($e) => $e->id === $episode->id) : 0;

        $source = $episode?->video_url ?? $content->video_url;

        return view('frontend.watch', [
            'content' => $content,
            'episode' => $episode,
            'episodes' => $content->episodes,
            'startIndex' => $startIndex === false ? 0 : (int) $startIndex,
            'youtubeId' => Content::youtubeIdFrom($source) ?? $content->youtube_id,
            'ad' => $ad,
        ]);
    }

    /** The eligible pre-roll ad payload for this title + viewer, or null. Never blocks playback. */
    private function preroll(Content $content, ?User $user): ?array
    {
        try {
            return AdCampaign::pickFor($content, $user)?->toPlayerPayload();
        } catch (\Throwable $e) {
            return null;   // table missing / any error → just skip the ad
        }
    }
}
