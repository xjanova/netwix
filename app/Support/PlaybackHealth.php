<?php

namespace App\Support;

use App\Models\Content;
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

    private const SET_TTL = 7 * 24 * 3600;   // remember failing viewers for a week
    private const COOLDOWN = 12 * 3600;      // grace window after an admin re-publishes

    /** A viewer couldn't play this title — count them once; suspend at the threshold. */
    public static function recordFailure(Content $content, string $viewer, string $reason): void
    {
        if (! $content->is_published || $content->suspended_at) {
            return; // already down / not live
        }
        if (Cache::has(self::cooldownKey($content->id))) {
            return; // just re-published by an admin — give it a grace window
        }
        try {
            $key = self::setKey($content->id);
            Redis::sadd($key, $viewer);
            Redis::expire($key, self::SET_TTL);
            if ((int) Redis::scard($key) >= self::THRESHOLD) {
                self::suspend($content, $reason);
            }
        } catch (Throwable $e) {
            // health tracking is best-effort — never break playback over it
        }
    }

    /** The title just played fine for someone → it's alive; clear the failing-viewer tally. */
    public static function recordSuccess(Content $content): void
    {
        try {
            Redis::del(self::setKey($content->id));
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

    /** Unpublish + tag for the admin "หยุดเผยแพร่" review list. */
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
    }

    private static function setKey(int $id): string
    {
        return "netwix:playfail:{$id}";
    }

    private static function cooldownKey(int $id): string
    {
        return "playhealth:cooldown:{$id}";
    }
}
