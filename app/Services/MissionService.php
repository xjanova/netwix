<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\MissionCompletion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mission watch-verification + reward. Anti-cheat is layered so a reward can't be farmed by
 * fast-forwarding or scripting:
 *   1) The client sends a heartbeat every ~15s ONLY while the video is playing AND the tab is visible.
 *   2) The server credits watched time from the REAL wall-clock gap between beats — a beat closer than
 *      MIN_INTERVAL credits nothing (spam/fast-forward), and a single beat credits at most MAX_CREDIT
 *      (so a long gap from a backgrounded tab can't dump a huge chunk).
 *   3) The reward is only granted once BOTH the accumulated watched-seconds AND the real elapsed time
 *      since start reach the mission's required_seconds — you can never finish faster than real time.
 *   4) The unique (user, mission, day) row makes a double-award race a no-op.
 * External YouTube still can't be verified 100% (it's an iframe), but this bounds farming to "keep a
 * focused tab playing for the full duration", per day.
 */
class MissionService
{
    private const MIN_INTERVAL = 8;   // beats closer than this credit nothing
    private const MAX_CREDIT = 20;    // a single beat credits at most this many seconds
    private const SLACK = 2;          // wall-clock slack (network jitter) before the real-time gate

    public function __construct(private Membership $membership, private GoldWallet $gold) {}

    /** Has the user already earned this mission for the current period (once = ever, daily = today)? */
    public function alreadyEarned(User $u, Mission $m): bool
    {
        $q = MissionCompletion::where('user_id', $u->id)->where('mission_id', $m->id);
        if ($m->repeat === 'daily') {
            $q->whereDate('day', now()->toDateString());
        }

        return $q->exists();
    }

    /** 'earned' (done for this period) | 'available'. */
    public function statusFor(User $u, Mission $m): string
    {
        return $this->alreadyEarned($u, $m) ? 'earned' : 'available';
    }

    /** Begin (or restart) a watch attempt. Issues a fresh token that binds subsequent heartbeats. */
    public function start(User $u, Mission $m): array
    {
        if ($this->alreadyEarned($u, $m)) {
            return ['ok' => false, 'earned' => true, 'error' => $m->repeat === 'daily' ? 'ภารกิจนี้รับรางวัลของวันนี้แล้ว' : 'ภารกิจนี้ทำสำเร็จแล้ว'];
        }

        $token = (string) Str::uuid();
        MissionAttempt::updateOrCreate(
            ['user_id' => $u->id, 'mission_id' => $m->id],
            ['token' => $token, 'started_at' => now(), 'last_beat_at' => now(), 'watched_seconds' => 0, 'awarded_at' => null],
        );

        return ['ok' => true, 'token' => $token, 'watched' => 0, 'required' => (int) $m->required_seconds];
    }

    /**
     * A heartbeat from an actively-playing, focused player. Returns the validated progress, and the
     * reward payload the moment the mission is completed.
     */
    public function beat(User $u, Mission $m, string $token): array
    {
        $attempt = MissionAttempt::where('user_id', $u->id)->where('mission_id', $m->id)->first();
        if (! $attempt || ! hash_equals((string) $attempt->token, $token)) {
            return ['ok' => false, 'error' => 'เริ่มภารกิจใหม่อีกครั้ง'];
        }
        if ($this->alreadyEarned($u, $m)) {
            return ['ok' => true, 'done' => true, 'watched' => (int) $m->required_seconds, 'required' => (int) $m->required_seconds];
        }

        $now = now();
        $delta = $attempt->last_beat_at ? $attempt->last_beat_at->diffInSeconds($now) : 0;
        $credit = $delta < self::MIN_INTERVAL ? 0 : min($delta, self::MAX_CREDIT);

        $required = (int) $m->required_seconds;
        $watched = min($required, (int) $attempt->watched_seconds + $credit);
        $attempt->watched_seconds = $watched;
        $attempt->last_beat_at = $now;
        $attempt->save();

        $elapsed = $attempt->started_at ? $attempt->started_at->diffInSeconds($now) : 0;

        // Award only when BOTH the validated watch time and the real elapsed time reach the requirement.
        if ($watched >= $required && $elapsed >= $required - self::SLACK) {
            $reward = $this->award($u, $m, $attempt);
            if ($reward !== null) {
                return ['ok' => true, 'done' => true, 'watched' => $watched, 'required' => $required, 'reward' => $reward];
            }
        }

        return ['ok' => true, 'done' => false, 'watched' => $watched, 'required' => $required];
    }

    /** Grant the reward once, guarded by the unique (user, mission, day) row. Returns the reward or null. */
    private function award(User $u, Mission $m, MissionAttempt $attempt): ?array
    {
        try {
            return DB::transaction(function () use ($u, $m, $attempt) {
                // Claim the slot first — a concurrent beat hits the unique key and aborts.
                MissionCompletion::create([
                    'user_id' => $u->id,
                    'mission_id' => $m->id,
                    'day' => now()->toDateString(),
                    'reward_kind' => $m->reward_kind,
                    'reward_amount' => (int) $m->reward_amount,
                    'completed_at' => now(),
                ]);

                if ($m->reward_kind === 'gold') {
                    $this->gold->addGold($u, (int) $m->reward_amount, 'mission', ['mission_id' => $m->id]);
                } else {
                    $this->membership->addCoins($u, (int) $m->reward_amount, 'mission');
                }

                $attempt->forceFill(['awarded_at' => now()])->save();

                return ['kind' => $m->reward_kind, 'amount' => (int) $m->reward_amount, 'label' => $m->rewardLabel()];
            });
        } catch (Throwable $e) {
            // Unique violation (already claimed by a racing beat) → treat as already awarded.
            return null;
        }
    }
}
