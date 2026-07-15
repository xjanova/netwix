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
    /** Clips longer than this are cut on the `clips-heavy` lane instead of the 2-worker pool. */
    private const HEAVY_SECONDS = 120;

    /** How many candidate titles to try when hunting for a never-posted episode. */
    private const TITLE_CANDIDATES = 30;

    /** How many different titles one slot may try before it finally gives up (skip-on-failure). */
    private const MAX_ATTEMPTS = 6;

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

        // First attempt for this slot. Nothing eligible at all → the slot is skipped. A failed
        // CUT later (source momentarily down) routes through advanceOnFailure(), which picks a
        // DIFFERENT title and tries again — so a broken source never costs the whole slot.
        if (! $this->dispatchCut($campaign, $post)) {
            $post->update([
                'status' => 'skipped',
                'error' => $campaign->episode_pick === 'unposted' ? 'no_unposted_episode' : 'no_title',
                'content_id' => null,
            ]);
        }

        $campaign->forceFill(['last_run_at' => now()])->saveQuietly();

        return $post;
    }

    /**
     * Pick a title this slot hasn't tried yet, cut it, and point the post at the new clip.
     * Returns false when nothing eligible is left to try (the caller decides skip vs fail).
     * Every call records the picked title in tried_content_ids and bumps attempts, so a retry
     * never re-picks a title that already failed this slot.
     */
    private function dispatchCut(ClipCampaign $campaign, ClipCampaignPost $post): bool
    {
        $exclude = array_map('intval', (array) $post->tried_content_ids);
        [$title, $episode] = $this->pickTarget($campaign, $exclude);
        if (! $title) {
            return false;
        }

        $full = (bool) $campaign->full_episode;
        // duration=0 is the "whole episode" sentinel ClipMaker understands (no -t, all segments).
        $duration = $full ? 0 : $this->pickDuration($campaign);

        // "ending" and "random" can only land on the RIGHT spot if the real media length is known,
        // and duration_minutes is usually absent for these sources (goseries4k/rongyok store none)
        // — so the runner defers to ClipMaker, which resolves the offset from the actual playlist/
        // probe. Only "middle" (a deterministic centre that's fine even on a rough length) is
        // computed here. Deferred modes carry start=0 + the mode; the cutter fills in the real start.
        $mode = (string) $campaign->start_mode;
        $deferred = ! $full && in_array($mode, ['ending', 'random'], true);
        $start = ($full || $deferred) ? 0 : $this->pickStart($episode?->duration_minutes, $duration, $mode);
        $clipMode = $full ? 'absolute' : ($deferred ? $mode : 'absolute');

        $clip = MarketingClip::create([
            'campaign_id' => $campaign->id,
            'content_id' => $title->id,
            'episode_id' => $episode?->id,
            'start' => $start,
            'start_mode' => $clipMode,
            'duration' => $duration,
            'aspect' => $campaign->aspect,
            'status' => 'pending',
            'auto_post' => true,
            'post_targets' => implode(',', $campaign->targetList()),
        ]);

        $post->update([
            'status' => 'cutting',
            'attempts' => (int) $post->attempts + 1,
            'content_id' => $title->id,
            'tried_content_ids' => array_values(array_unique([...$exclude, $title->id])),
            'marketing_clip_id' => $clip->id,
            'error' => null,
            'dry_run' => false,
        ]);

        // Cut on the bounded CLI pool. On success the job auto-captions and dispatches the FB
        // post; on failure it calls advanceOnFailure() to try the next title (see GenerateMarketingClip).
        //
        // Long cuts MUST NOT enter the 2-worker `clips` pool: that lane's worker dies at 310s,
        // and a multi-minute clip (let alone a whole episode) is a download + re-encode that
        // routinely outruns it — the job would be killed mid-encode and retried forever. They
        // go to the single-worker `clips-heavy` lane (hours-scale timeout, withoutOverlapping)
        // which also keeps two heavy ffmpeg runs off this shared box at once.
        $heavy = $full || $duration > self::HEAVY_SECONDS;
        GenerateMarketingClip::dispatch($clip->id, heavy: $heavy)->onQueue($heavy ? 'clips-heavy' : 'clips');

        return true;
    }

    /**
     * A campaign clip's cut failed. Skip that title and try another, up to MAX_ATTEMPTS per slot,
     * so a momentarily-broken source (dead wowdrama token, a CDN blip) doesn't cost the whole
     * slot. Only when the attempt budget is spent — or nothing eligible is left to try — is the
     * slot finally marked failed. The just-failed title is already in tried_content_ids, so the
     * retry always moves on to a different title.
     */
    public function advanceOnFailure(ClipCampaignPost $post, string $reason): void
    {
        $campaign = $post->campaign;
        if (! $campaign || (int) $post->attempts >= self::MAX_ATTEMPTS || ! $this->dispatchCut($campaign, $post)) {
            $post->markFailed($reason);
        }
    }

    // ---- title + episode selection -------------------------------------------

    /**
     * Pick what this run will cut: [title, episode].
     *
     * For every mode but "unposted" the two choices are independent (title first, then an
     * episode of it). "unposted" is the no-repeat mode the hourly series/cartoon campaigns
     * use: it must land on a (title, episode) pair this campaign has NEVER posted, so the
     * two choices are coupled — a title whose episodes are exhausted is skipped and the next
     * candidate is tried. Truth comes from the clip history itself, not a stored cursor, so
     * it stays correct even if rows are deleted or campaigns are edited.
     *
     * $exclude lists content ids this slot has already tried and failed to cut — the skip-on-
     * failure retry passes it so a broken title is never re-picked (see advanceOnFailure).
     *
     * @param  array<int, int>  $exclude
     * @return array{0: ?Content, 1: ?Episode}
     */
    private function pickTarget(ClipCampaign $campaign, array $exclude = []): array
    {
        if ($campaign->episode_pick !== 'unposted') {
            $title = $this->pickTitle($campaign, $exclude);

            return [$title, $title ? $this->pickEpisode($campaign, $title) : null];
        }

        // Deliberately GLOBAL, not per-campaign: what must not repeat is what the PAGE shows.
        // The series and cartoon campaigns fish from overlapping pools (most anime here is
        // type=series), so a per-campaign memory would let both tease the same episode hours
        // apart. Failed cuts are not counted — a source that broke once may work next time.
        $posted = MarketingClip::whereNotNull('episode_id')
            ->where('status', '!=', 'failed')
            ->pluck('episode_id')->all();

        foreach ($this->titleCandidates($campaign, $exclude) as $title) {
            $episode = Episode::where('content_id', $title->id)
                ->when($posted, fn ($q) => $q->whereNotIn('id', $posted))
                ->orderBy('sort')->orderBy('id')->first();
            if ($episode) {
                return [$title, $episode];
            }
        }

        return [null, null];
    }

    /**
     * Choose a title for this campaign per its filter + repeat guard, or null if the pool is dry.
     *
     * @param  array<int, int>  $exclude  content ids this slot already failed to cut
     */
    private function pickTitle(ClipCampaign $campaign, array $exclude = []): ?Content
    {
        // A pinned title always wins (still must have an episode to cut from) — unless it's the
        // very title that just failed this slot, in which case there is nothing else to try.
        if ($campaign->content_id) {
            return in_array((int) $campaign->content_id, $exclude, true)
                ? null
                : Content::withoutGlobalScopes()->whereKey($campaign->content_id)->has('episodes')->first();
        }

        return $this->ordered($this->titleQuery($campaign, $exclude), $campaign)->first();
    }

    /**
     * The first N eligible titles in this campaign's preferred order — the pool the no-repeat
     * mode walks. Bounded so a campaign whose whole catalogue is exhausted still finishes fast.
     *
     * @param  array<int, int>  $exclude  content ids this slot already failed to cut
     * @return \Illuminate\Support\Collection<int, Content>
     */
    private function titleCandidates(ClipCampaign $campaign, array $exclude = [])
    {
        if ($campaign->content_id) {
            return in_array((int) $campaign->content_id, $exclude, true)
                ? Content::withoutGlobalScopes()->whereRaw('1=0')->get()
                : Content::withoutGlobalScopes()->whereKey($campaign->content_id)->has('episodes')->get();
        }

        return $this->ordered($this->titleQuery($campaign, $exclude), $campaign)->limit(self::TITLE_CANDIDATES)->get();
    }

    /** Apply the campaign's pick strategy to a title query. */
    private function ordered($q, ClipCampaign $campaign)
    {
        return match ($campaign->pick) {
            'random' => $q->inRandomOrder(),
            'newest' => $q->orderByDesc('id'),
            default => $q->orderByDesc('views')->orderByDesc('id'), // "trending" = most-watched
        };
    }

    /**
     * Restrict (or exclude) a title query by one of the catalogue's content types.
     *
     * "anime" is NOT a `type` in this catalogue — it's a genre umbrella (อนิเมะ/การ์ตูน),
     * exactly how BrowseController + HeroBillboard scope the /anime hub. Filtering by
     * type='anime' would silently match zero titles, so it maps to those genres instead.
     */
    private function applyTypeScope($q, string $type, bool $exclude): void
    {
        if ($type === '') {
            return;
        }

        if ($type === 'anime') {
            $ids = Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
            $exclude
                ? $q->whereDoesntHave('genres', fn ($g) => $g->whereIn('genres.id', $ids))
                : $q->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $ids));

            return;
        }

        $exclude ? $q->where('type', '!=', $type) : $q->where('type', $type);
    }

    /**
     * Every title matching this campaign's filters + repeat guard (unordered).
     *
     * @param  array<int, int>  $exclude  content ids this slot already failed to cut
     */
    private function titleQuery(ClipCampaign $campaign, array $exclude = [])
    {
        $q = Content::withoutGlobalScopes()
            ->where('is_published', true)
            ->whereNull('suspended_at')
            ->has('episodes')
            ->when($campaign->source, fn ($qq) => $qq->where('source', $campaign->source))
            ->when($campaign->genre_id, fn ($qq) => $qq->whereHas('genres', fn ($g) => $g->where('genres.id', $campaign->genre_id)))
            ->when(! $campaign->include_adult, fn ($qq) => $qq->where(
                fn ($w) => $w->whereNull('maturity')->orWhereNotIn('maturity', Maturity::ADULT)
            ))
            ->when($exclude, fn ($qq) => $qq->whereNotIn('id', $exclude));

        $this->applyTypeScope($q, (string) $campaign->content_type, false);
        $this->applyTypeScope($q, (string) $campaign->exclude_type, true);

        $recent = $campaign->recentlyPostedContentIds();
        if ($recent) {
            $q->whereNotIn('id', $recent);
        }

        return $q;
    }

    // ---- episode selection ----------------------------------------------------

    /**
     * Which EPISODE of the picked title to cut from, per the campaign's episode_pick:
     * first (default, the old behaviour), random, or sequential — continue from the last
     * episode this campaign posted for this title, wrapping back to episode 1 at the end.
     * ("unposted" never reaches here — it is resolved together with the title in pickTarget.)
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

        // Short content (rongyok CN micro-dramas run 1-2 min): if the clip is as long as the whole
        // episode, take it from the top instead of seeking past the end. ClipMaker's -t just
        // encodes whatever is actually there.
        if ($total > 0 && $total <= $duration) {
            return 0;
        }
        if ($total < $duration + 60) {
            // Barely longer than the clip (or unknown length) — a tiny/centred offset, never 120s
            // into a 90s episode.
            return $total > 0 ? max(0, (int) round(($total - $duration) / 2)) : 120;
        }

        $margin = (int) round($total * 0.08);
        $lo = $margin;
        $hi = max($lo, $total - $margin - $duration);

        return $mode === 'random'
            ? random_int($lo, $hi)
            : (int) round(($lo + $hi) / 2);
    }
}
