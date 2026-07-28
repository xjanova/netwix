<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Auto-suspends un-playable titles. When THRESHOLD distinct viewers can't play a title (a dead
 * upstream link caught server-side, or a fatal player error reported by the browser) it is
 * unpublished and parked for admin review (re-source or delete). The failing-viewer tally RESETS
 * the moment anyone plays the title fine, so one flaky connection can never sink a working movie.
 */
class PlaybackHealth
{
    /** Distinct viewers who must fail before a title is auto-suspended (owner: "เกิน 5 คน"). */
    public const THRESHOLD = 5;

    /** The SAME viewer failing this many times flags the link for review at once (owner: 3 tries). */
    public const FAIL_ATTEMPTS = 3;

    private const SET_TTL = 7 * 24 * 3600;   // remember failing viewers for a week
    private const COOLDOWN = 12 * 3600;      // grace window after an admin re-publishes

    /** Most titles auto-death may unpublish per DEAD_WINDOW before it assumes an outage — see
     *  [self::withinDeathBudget]. Beyond this the extras are only flagged for review. */
    private const DEAD_BUDGET = 25;

    private const DEAD_WINDOW = 900;         // 15 minutes

    /** A viewer couldn't play this title — count them once; suspend at the threshold. */
    public static function recordFailure(Content $content, string $viewer, string $reason): void
    {
        if (! $content->is_published || $content->suspended_at) {
            return; // already down / not live
        }
        if (Cache::has(self::cooldownKey($content->id))) {
            return; // just re-published by an admin — give it a grace window
        }
        if (self::sourceIsDown($content)) {
            return; // whole source is out — see [self::sourceIsDown]
        }
        try {
            $key = self::setKey($content->id);
            Redis::sadd($key, $viewer);
            Redis::expire($key, self::SET_TTL);

            // Fast early-warning: the SAME viewer failing FAIL_ATTEMPTS times almost certainly means the
            // link is dead — flag it for admin review NOW, without waiting for THRESHOLD distinct viewers.
            $vKey = self::viewerFailKey($content->id, $viewer);
            $attempts = (int) Redis::incr($vKey);
            Redis::expire($vKey, self::SET_TTL);
            if ($attempts >= self::FAIL_ATTEMPTS) {
                // Flag for review (DB, durable) — unless the admin verified the link is fine
                // (review_ignored). The conditional WHERE never re-flags an OK'd or already-flagged title.
                Content::whereKey($content->id)->whereNull('review_flagged_at')->where('review_ignored', false)
                    ->update(['review_flagged_at' => now()]);
            }

            // Harder safety net: enough DISTINCT viewers can't play it → auto-unpublish. Admin-toggleable
            // (Setting playback_auto_suspend) — off = leave it published, only flag for review.
            if (Setting::flag('playback_auto_suspend', true) && (int) Redis::scard($key) >= self::THRESHOLD) {
                self::suspend($content, $reason);
            }
        } catch (Throwable $e) {
            // health tracking is best-effort — never break playback over it
        }
    }

    /** The title just played fine for someone → it's alive; clear the failing tally + review flag. */
    public static function recordSuccess(Content $content): void
    {
        try {
            Redis::del(self::setKey($content->id));
            Redis::del(self::viewerFailKey($content->id, self::viewer()));  // reset this viewer's fail streak
        } catch (Throwable $e) {
            // ignore
        }
        // Plays fine now → clear the auto review-flag (leave review_ignored — that's the admin's call).
        try {
            Content::whereKey($content->id)->whereNotNull('review_flagged_at')->update(['review_flagged_at' => null]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * Every link in the title's rotation failed — "ครบรอบ ถือว่าหนังตาย" — so take it down at once,
     * without waiting for THRESHOLD distinct viewers. Called by [App\Support\MirrorRotation] only after
     * a FULL cycle of definitive failures (a transient/network failure anywhere in the chain doesn't
     * get here), and it still honours every safety rail suspension has:
     *  - the admin's playback_auto_suspend switch,
     *  - the post-republish grace window,
     *  - DEAD_BUDGET, which stops a global outage from unpublishing the whole catalogue in one sweep.
     * When a rail blocks the unpublish the title is still flagged for admin review, so nothing is lost.
     */
    public static function declareDead(Content $content, string $reason = 'all_links_failed'): void
    {
        if (! $content->is_published || $content->suspended_at) {
            return;
        }
        if (Cache::has(self::cooldownKey($content->id))) {
            return;   // an admin just re-published it — leave their call alone
        }
        if (self::sourceIsDown($content)) {
            return;   // whole source is out — see [self::sourceIsDown]
        }

        if (! Setting::flag('playback_auto_suspend', true) || ! self::withinDeathBudget()) {
            self::flagForReview($content);

            return;
        }

        self::suspend($content, $reason);
        try {
            Redis::del(self::viewerFailKey($content->id, self::viewer()));
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * True when the canary has this title's source flagged as wholly down ([SourceHealth]). Nothing is
     * wrong with the TITLE then — 24-hdx's Cloudflare challenge took ~6,500 of them out in one second
     * — and unpublishing the catalogue an item at a time would only have to be undone by hand once the
     * source recovers. Failures are still recorded upstream in the logs; only the suspension stops.
     */
    private static function sourceIsDown(Content $content): bool
    {
        return $content->source !== null && SourceHealth::isDown((string) $content->source);
    }

    /**
     * Rate-limit auto-death. If every source is unreachable at once (our DNS, an upstream provider,
     * a bad deploy) a whole-catalogue sweep would unpublish thousands of perfectly good titles, and
     * only a human can tell that apart from a genuinely dead link. Past DEAD_BUDGET auto-deaths in a
     * DEAD_WINDOW the rest are only flagged — the owner sees the pile-up in review instead.
     */
    private static function withinDeathBudget(): bool
    {
        try {
            $key = 'netwix:autodead:'.intdiv(time(), self::DEAD_WINDOW);
            $n = (int) Redis::incr($key);
            Redis::expire($key, self::DEAD_WINDOW * 2);

            if ($n === self::DEAD_BUDGET + 1) {
                Log::error('playback: auto-death budget hit — suspending only flags from here', [
                    'budget' => self::DEAD_BUDGET, 'window_seconds' => self::DEAD_WINDOW,
                ]);
            }

            return $n <= self::DEAD_BUDGET;
        } catch (Throwable $e) {
            return true;   // no Redis → fall back to the un-throttled behaviour rather than blocking
        }
    }

    /** Park a title in the admin review queue without unpublishing it. */
    private static function flagForReview(Content $content): void
    {
        try {
            Content::whereKey($content->id)->whereNull('review_flagged_at')->where('review_ignored', false)
                ->update(['review_flagged_at' => now()]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    /** Admin re-publish: bring it back + a grace window so it isn't instantly re-suspended. */
    public static function republish(Content $content): void
    {
        $content->forceFill([
            'is_published' => true,
            'suspended_at' => null,
            'suspend_reason' => null,
            'playback_fail_count' => 0,
            'review_flagged_at' => null,
        ])->save();
        try {
            Redis::del(self::setKey($content->id));
        } catch (Throwable $e) {
            // ignore
        }
        Cache::put(self::cooldownKey($content->id), true, self::COOLDOWN);
        Cache::forget('admin:suspended_count');
    }

    /** Cached count for the admin nav badge. */
    public static function suspendedCount(): int
    {
        return Cache::remember('admin:suspended_count', now()->addSeconds(60),
            fn () => Content::suspended()->count());
    }

    /** Stable per-viewer id: the logged-in user if any, else the client IP. */
    public static function viewer(): string
    {
        return (string) (auth()->id() ?? request()->ip() ?? 'anon');
    }

    private static function suspend(Content $content, string $reason): void
    {
        $fails = 0;
        try {
            $fails = (int) Redis::scard(self::setKey($content->id));
        } catch (Throwable $e) {
            // ignore
        }

        $content->forceFill([
            'is_published' => false,
            'suspended_at' => now(),
            'suspend_reason' => $reason,
            'playback_fail_count' => max($fails, self::THRESHOLD),
        ])->save();

        try {
            Redis::del(self::setKey($content->id));
        } catch (Throwable $e) {
            // ignore
        }
        Cache::forget('admin:suspended_count');
        Log::warning('playback: auto-suspended un-playable title', [
            'content_id' => $content->id, 'title' => $content->title, 'reason' => $reason, 'fails' => $fails,
        ]);

        // Collected, not pushed: this fires per TITLE, and one dead source produces thousands of
        // them. [LineNotifier::flushDigest] sends the hour's worth as a single message.
        LineNotifier::noteSuspended($content->id, (string) $content->title, (string) $content->source);
    }

    private static function setKey(int $id): string
    {
        return "netwix:playfail:{$id}";
    }

    /** Per-(content, viewer) failed-attempt counter — hashed so the key carries no raw IP. */
    private static function viewerFailKey(int $id, string $viewer): string
    {
        return "netwix:playfail:v:{$id}:".sha1($viewer);
    }

    private static function cooldownKey(int $id): string
    {
        return "playhealth:cooldown:{$id}";
    }
}
