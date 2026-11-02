<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared web/app bonus campaigns. Ordinary activity rewards continue alongside these bonuses. */
class RewardCampaigns
{
    public const TIMEZONE = 'Asia/Bangkok';

    public const DEFAULTS = [
        'daily' => ['title' => 'ตอกบัตรนักดู', 'kind' => 'daily', 'target' => 1, 'coins' => 3, 'repeat' => 'daily', 'seconds' => 300, 'enabled' => true],
        'new_titles' => ['title' => 'วันนี้ลองเรื่องใหม่', 'kind' => 'watch', 'target' => 3, 'coins' => 15, 'repeat' => 'daily', 'seconds' => 300, 'enabled' => true],
        'explorer' => ['title' => 'พาสปอร์ต 3 หมวด', 'kind' => 'categories', 'target' => 3, 'coins' => 25, 'repeat' => 'weekly', 'seconds' => 300, 'enabled' => true],
        'share' => ['title' => 'ส่งต่อเรื่องโปรด', 'kind' => 'share', 'target' => 1, 'coins' => 2, 'repeat' => 'daily', 'seconds' => 300, 'enabled' => true],
        'referral' => ['title' => 'เพื่อนใหม่ร่วมจอ', 'kind' => 'referral', 'target' => 1, 'coins' => 10, 'repeat' => 'daily', 'seconds' => 300, 'enabled' => true],
    ];

    public function __construct(private Membership $membership) {}

    public function config(): array
    {
        $cfg = $this->membership->config()['engagement'] ?? [];
        $items = [];
        foreach (self::DEFAULTS as $key => $defaults) {
            $items[$key] = array_replace($defaults, $cfg[$key] ?? []);
            $items[$key]['kind'] = $defaults['kind']; // action identity is never client/admin supplied
        }

        return $items;
    }

    private function window(array $c): array
    {
        $day = now(self::TIMEZONE)->startOfDay();

        return match ($c['repeat']) {
            'weekly' => ['w:'.$day->copy()->startOfWeek()->toDateString(), $day->copy()->startOfWeek()->toDateString(), $day->copy()->endOfWeek()->toDateString(), $day->copy()->startOfWeek()->addWeek()->toIso8601String()],
            'once' => ['all', null, null, null],
            default => ['d:'.$day->toDateString(), $day->toDateString(), $day->toDateString(), $day->copy()->addDay()->toIso8601String()],
        };
    }

    private function progress(User $u, array $c): int
    {
        [, $from, $to] = $this->window($c);
        $kind = $c['kind'] === 'categories' ? 'watch' : $c['kind'];
        $q = DB::table('reward_activity')->where('user_id', $u->id)->where('kind', $kind);
        if ($from) {
            $q->whereBetween('day', [$from, $to]);
        }
        if ($kind === 'watch') {
            $q->where('seconds', '>=', $c['seconds']);

            return $c['kind'] === 'categories'
                ? $q->whereIn('category', ['movie', 'series', 'vertical'])->distinct()->count('category')
                : $q->distinct()->count('subject');
        }

        return $q->count();
    }

    public function state(User $u): array
    {
        $items = [];
        foreach ($this->config() as $key => $c) {
            if (! $c['enabled']) {
                continue;
            }
            [$period, , , $reset] = $this->window($c);
            $done = DB::table('reward_campaign_completions')->where(['user_id' => $u->id, 'campaign' => $key, 'period' => $period])->exists();
            $minutes = (int) ceil($c['seconds'] / 60);
            $description = match ($c['kind']) {
                'daily' => 'เช็กอินรับเหรียญประจำวัน แล้วรับโบนัสเพิ่ม',
                'watch' => "ดู {$c['target']} เรื่องที่ไม่ซ้ำกัน เรื่องละอย่างน้อย {$minutes} นาที",
                'categories' => "ดูหนัง ซีรีส์ หรือแนวตั้งให้ครบ {$c['target']} หมวด เรื่องละอย่างน้อย {$minutes} นาที",
                'share' => "แชร์หรือคัดลอกลิงก์ชวนเพื่อน {$c['target']} ครั้ง (ไม่ต้องรอเพื่อนสมัคร)",
                'referral' => "เพื่อน {$c['target']} คนสมัครหรือใช้โค้ดแนะนำสำเร็จ รับโบนัสเพิ่มจากรางวัลชวนเพื่อนปกติ",
            };
            $items[] = ['key' => $key, 'title' => $c['title'], 'description' => $description, 'kind' => $c['kind'],
                'target' => (int) $c['target'], 'progress' => min((int) $c['target'], $this->progress($u, $c)),
                'coins' => (int) $c['coins'], 'repeat' => $c['repeat'], 'status' => $done ? 'earned' : 'available',
                'reset_at' => $reset, 'seconds' => (int) $c['seconds']];
        }

        return $items;
    }

    /** Call under the user's row lock; unique completion and ledger credit share the transaction. */
    private function awardEligible(User $u): array
    {
        $awards = [];
        foreach ($this->config() as $key => $c) {
            if (! $c['enabled'] || $this->progress($u, $c) < $c['target']) {
                continue;
            }
            [$period] = $this->window($c);
            $claimed = DB::table('reward_campaign_completions')->insertOrIgnore([
                'user_id' => $u->id, 'campaign' => $key, 'period' => $period, 'coins' => $c['coins'], 'completed_at' => now(),
            ]);
            if ($claimed) {
                $this->membership->addCoins($u, (int) $c['coins'], 'mission');
                $awards[] = ['key' => $key, 'title' => $c['title'], 'coins' => (int) $c['coins']];
            }
        }

        return $awards;
    }

    public function record(User $u, string $kind, string $subject): array
    {
        return DB::transaction(function () use ($u, $kind, $subject) {
            $user = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            DB::table('reward_activity')->insertOrIgnore(['user_id' => $user->id, 'kind' => $kind,
                'subject' => $subject, 'day' => now(self::TIMEZONE)->toDateString(), 'seconds' => 0]);

            return $this->awardEligible($user);
        }, 3);
    }

    public function startWatch(User $u, Content $content, ?Episode $episode, float $position): array
    {
        abort_unless($content->is_published, 404);
        abort_if($content->requires_pro && ! $u->isProMember(), 403);
        abort_if($content->is_vip && app(GoldWallet::class)->vipAccess($u, $content) === 'locked', 403);
        abort_if($episode && $episode->content_id !== $content->id, 422);

        return DB::transaction(function () use ($u, $content, $episode, $position) {
            User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            $token = Str::random(48);
            $day = now(self::TIMEZONE)->toDateString();
            DB::table('reward_watch_sessions')->updateOrInsert(['user_id' => $u->id], [
                'content_id' => $content->id, 'episode_id' => $episode?->id, 'day' => $day,
                'token_hash' => hash('sha256', $token), 'position' => $position, 'last_beat_at' => now(),
            ]);
            DB::table('reward_activity')->insertOrIgnore(['user_id' => $u->id, 'kind' => 'watch',
                'subject' => (string) $content->id, 'day' => $day, 'category' => $content->type, 'seconds' => 0]);

            return ['token' => $token];
        }, 3);
    }

    public function watchBeat(User $u, string $token, float $position): array
    {
        return DB::transaction(function () use ($u, $token, $position) {
            $user = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            $session = DB::table('reward_watch_sessions')->where('user_id', $u->id)->first();
            if (! $session || ! hash_equals($session->token_hash, hash('sha256', $token)) || $session->day !== now(self::TIMEZONE)->toDateString()) {
                return ['ok' => false, 'error' => 'watch_restart'];
            }
            $elapsed = Carbon::parse($session->last_beat_at)->diffInSeconds(now());
            $content = Content::find($session->content_id);
            if (! $content || ! $content->is_published || ($content->requires_pro && ! $user->isProMember()) ||
                ($content->is_vip && app(GoldWallet::class)->vipAccess($user, $content) === 'locked')) {
                return ['ok' => false, 'error' => 'watch_restart'];
            }
            $advance = $position - $session->position;
            // A seek, pause/background gap or rapid spam earns no time. One active token per account.
            $credit = $elapsed >= 8 && $elapsed <= 30 && $advance > 0 && $advance <= $elapsed + 3
                ? (int) floor(min(20, $elapsed, $advance)) : 0;
            DB::table('reward_watch_sessions')->where('user_id', $u->id)->update(['position' => $position, 'last_beat_at' => now()]);
            $q = DB::table('reward_activity')->where(['user_id' => $u->id, 'kind' => 'watch', 'subject' => (string) $session->content_id, 'day' => $session->day]);
            if ($credit) {
                $q->increment('seconds', $credit);
            }

            return ['ok' => true, 'seconds' => (int) $q->value('seconds'), 'awards' => $this->awardEligible($user), 'membership' => $this->membership->state($user->refresh())];
        }, 3);
    }
}
