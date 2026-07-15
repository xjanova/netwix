<?php

namespace App\Support;

use App\Jobs\GenerateMarketingClip;
use App\Models\ClipCampaign;
use App\Models\ClipCampaignPost;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\MarketingClip;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The brain of the clip auto-post pipeline (Phase 3). Given a campaign that has come due,
 * it: picks a title → creates the campaign-post + clip rows → enqueues the ffmpeg cut.
 *
 * IMPORTANT — this class does NO heavy work itself. It only writes rows and dispatches a
 * queue job, so it is safe to call from the every-5-minute scheduler tick. The actual clip
 * cut (ffmpeg) happens on the bounded CLI `clips` worker pool, and the Facebook upload on
 * the `clips-post` lane. This decoupling is deliberate: running ffmpeg inside the scheduler
 * tick is exactly what stacked workers and crashed the box on 2026-07-06.
 */
class ClipCampaignRunner
{
    /**
     * Fire every campaign whose slot is due at $now. Returns [campaignId => slot] for logging.
     *
     * @return array<int, string>
     */
    public function runDue(Carbon $now): array
    {
        $fired = [];
        foreach (ClipCampaign::where('is_enabled', true)->get() as $campaign) {
            $slot = $campaign->dueSlot($now);
            if ($slot === null) {
                continue;
            }
            $post = $this->fire($campaign, $slot, $now->toDateString());
            if ($post && $post->status === 'cutting') {
                $fired[$campaign->id] = $slot;
            }
        }

        return $fired;
    }

    /**
     * Produce one post for a (campaign, date, slot). The unique row is the double-post guard:
     * a slot already claimed today is a no-op (returns the existing row untouched) unless
     * $manual is set (the admin's "โพสต์ทันที" button, which re-runs the slot on demand).
     */
    public function fire(ClipCampaign $campaign, string $slot, string $date, bool $manual = false): ?ClipCampaignPost
    {
        try {
            $post = ClipCampaignPost::firstOrCreate(
                ['campaign_id' => $campaign->id, 'post_date' => $date, 'slot_time' => $slot],
                ['status' => 'pending'],
            );
        } catch (Throwable $e) {
            // A concurrent tick won the unique key — the other worker owns this slot.
            return null;
        }

        // Already handled this slot: leave a scheduled run alone; a manual run may retry.
        if (! $post->wasRecentlyCreated && ! $manual && $post->status !== 'pending') {
            return $post;
        }

        $title = $this->pickTitle($campaign);
        if (! $title) {
            $post->update(['status' => 'skipped', 'error' => 'no_title', 'content_id' => null]);

            return $post;
        }

        $episode = $this->pickEpisode($campaign, $title);
        $full = (bool) $campaign->full_episode;
        // duration=0 is the "whole episode" sentinel ClipMaker understands (no -t, all segments).
        $duration = $full ? 0 : $this->pickDuration($campaign);
        $start = $full ? 0 : $this->pickStart($episode?->duration_minutes, $duration, (string) $campaign->start_mode);

        $clip = MarketingClip::create([
            'campaign_id' => $campaign->id,
            'content_id' => $title->id,
            'episode_id' => $episode?->id,
            'start' => $start,
            'duration' => $duration,
            'aspect' => $campaign->aspect,
            'status' => 'pending',
            'auto_post' => true,
            'post_targets' => implode(',', $campaign->targetList()),
        ]);

        $post->update([
            'status' => 'cutting',
            'content_id' => $title->id,
            'marketing_clip_id' => $clip->id,
            'error' => null,
            'dry_run' => false,
        ]);

        $campaign->forceFill(['last_run_at' => now()])->saveQuietly();

        // Cut on the bounded CLI pool. On success the job auto-captions and dispatches the
        // Facebook post; on failure it flags this campaign-post (see GenerateMarketingClip).
        // A full-episode cut re-encodes the entire file — that MUST NOT enter the 2-worker
        // clips pool (310s worker timeout + shared box), so it goes to the single-worker
        // clips-heavy lane with an hours-scale timeout instead.
        GenerateMarketingClip::dispatch($clip->id, heavy: $full)->onQueue($full ? 'clips-heavy' : 'clips');

        return $post;
    }

    // ---- title selection ----------------------------------------------------

    /** Choose a title for this campaign per its filter + repeat guard, or null if the pool is dry. */
    private function pickTitle(ClipCampaign $campaign): ?Content
    {
        // A pinned title always wins (still must have an episode to cut from).
        if ($campaign->content_id) {
            return Content::withoutGlobalScopes()->whereKey($campaign->content_id)->has('episodes')->first();
        }

        $q = Content::withoutGlobalScopes()
            ->where('is_published', true)
            ->whereNull('suspended_at')
            ->has('episodes')
            ->when($campaign->source, fn ($qq) => $qq->where('source', $campaign->source))
            ->when($campaign->genre_id, fn ($qq) => $qq->whereHas('genres', fn ($g) => $g->where('genres.id', $campaign->genre_id)))
            ->when(! $campaign->include_adult, fn ($qq) => $qq->where(
                fn ($w) => $w->whereNull('maturity')->orWhereNotIn('maturity', Maturity::ADULT)
            ));

        // "anime" is NOT a `type` in this catalogue — it's a genre umbrella (อนิเมะ/การ์ตูน),
        // exactly how BrowseController + HeroBillboard scope the /anime hub. Filtering by
        // type='anime' would silently match zero titles, so map it to those genres instead.
        if ($campaign->content_type === 'anime') {
            $animeIds = Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
            $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $animeIds));
        } elseif ($campaign->content_type) {
            $q->where('type', $campaign->content_type);
        }

        $recent = $campaign->recentlyPostedContentIds();
        if ($recent) {
            $q->whereNotIn('id', $recent);
        }

        return match ($campaign->pick) {
            'random' => $q->inRandomOrder()->first(),
            'newest' => $q->orderByDesc('id')->first(),
            default => $q->orderByDesc('views')->orderByDesc('id')->first(), // "trending" = most-watched
        };
    }

    // ---- episode selection ----------------------------------------------------

    /**
     * Which EPISODE of the picked title to cut from, per the campaign's episode_pick:
     * first (default, the old behaviour), random, or sequential — continue from the last
     * episode this campaign posted for this title, wrapping back to episode 1 at the end.
     */
    private function pickEpisode(ClipCampaign $campaign, Content $title): ?Episode
    {
        $ordered = fn () => Episode::where('content_id', $title->id)->orderBy('sort')->orderBy('id');

        return match ($campaign->episode_pick) {
            'random' => Episode::where('content_id', $title->id)->inRandomOrder()->first(),
            'sequential' => $this->nextEpisode($campaign, $title) ?? $ordered()->first(),
            default => $ordered()->first(),
        };
    }

    /**
     * The episode AFTER the one this campaign last cut for this title (by sort order), or
     * null when there is no history / the last one was the final episode (caller wraps).
     * Derived from clip history instead of a stored cursor so it can never drift.
     */
    private function nextEpisode(ClipCampaign $campaign, Content $title): ?Episode
    {
        $lastEpisodeId = MarketingClip::where('campaign_id', $campaign->id)
            ->where('content_id', $title->id)
            ->whereNotNull('episode_id')
            ->latest('id')->value('episode_id');
        $last = $lastEpisodeId ? Episode::find($lastEpisodeId) : null;
        if (! $last) {
            return null;
        }

        return Episode::where('content_id', $title->id)
            ->where(fn ($w) => $w
                ->where('sort', '>', $last->sort)
                ->orWhere(fn ($w2) => $w2->where('sort', $last->sort)->where('id', '>', $last->id)))
            ->orderBy('sort')->orderBy('id')->first();
    }

    // ---- clip window ----------------------------------------------------------

    /** Clip length in seconds — fixed, or randomised in [duration, duration_max] when a max is set. */
    private function pickDuration(ClipCampaign $campaign): int
    {
        $min = max(5, (int) $campaign->duration);
        $max = (int) ($campaign->duration_max ?? 0);

        return $max > $min ? random_int($min, $max) : $min;
    }

    /**
     * WHERE the clip starts. Both modes skip the intro/credits ~8% margins; "middle" is the
     * deterministic centre (old behaviour), "random" rolls a fresh spot inside the watchable
     * range every post. Falls back to a safe 2-minute mark when the length is unknown.
     */
    private function pickStart(?int $episodeMinutes, int $duration, string $mode): int
    {
        $total = $episodeMinutes ? $episodeMinutes * 60 : 0;
        if ($total < $duration + 60) {
            return 120;
        }
        $margin = (int) round($total * 0.08);
        $lo = $margin;
        $hi = max($lo, $total - $margin - $duration);

        return $mode === 'random'
            ? random_int($lo, $hi)
            : (int) round(($lo + $hi) / 2);
    }
}
