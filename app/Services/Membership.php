<?php

namespace App\Services;

use App\Models\CoinTransaction;
use App\Models\Episode;
use App\Models\EpisodeUnlock;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The single source of truth for membership rules — Pro access, coins, the
 * referral promo, and episode gating. Every rule is admin-configurable (stored
 * as one JSON blob in Settings, merged over DEFAULTS), so the web and the mobile
 * app both read the SAME rules: the app just calls /api/app/membership/config
 * and /api/app/membership. Web is authoritative; the app follows.
 */
class Membership
{
    /** Baseline rules; the admin promo builder overrides any of these. */
    public const DEFAULTS = [
        'referral' => [
            'enabled' => true,
            'referee_pro_days' => 30,   // person who REDEEMS a code
            'referee_coins' => 0,
            'referrer_pro_days' => 30,  // code OWNER, per successful referral
            'referrer_coins' => 15,
            'max_referrals' => 0,       // 0 = unlimited
        ],
        'free_episodes' => 3,           // free eps per title before the lock
        'unlock_cost_coins' => 5,       // coins to unlock one more episode
        'signup_bonus_coins' => 10,     // granted once on registration
        'pro' => [
            'price_thb' => 129,
            'removes_ads' => true,
            'unlocks_all' => true,
        ],
        'earn' => [
            'daily_checkin_coins' => 2,
            'watch_reward_coins' => 3,
            'watch_reward_daily_cap' => 5,
            'like_coins' => 1,
            'like_daily_cap' => 5,
            'comment_coins' => 2,
            'comment_daily_cap' => 3,
            'share_coins' => 1,
            'share_daily_cap' => 3,
        ],
    ];

    private const KEY = 'membership_config';

    /** Merged rules (defaults + admin overrides). */
    public function config(): array
    {
        $raw = Setting::get(self::KEY);
        $override = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return array_replace_recursive(self::DEFAULTS, is_array($override) ? $override : []);
    }

    public function saveConfig(array $config): void
    {
        Setting::write(self::KEY, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    // ---- Pro status ----------------------------------------------------

    /** Pro if on a paid tier OR inside a time-limited grant (referral/promo). */
    public function isPro(User $u): bool
    {
        if (in_array($u->plan, ['standard', 'premium'], true)) {
            return true;
        }

        return $u->pro_until !== null && $u->pro_until->isFuture();
    }

    /** Extend the time-limited Pro grant by N days (stacks on remaining time). */
    public function grantProDays(User $u, int $days): void
    {
        if ($days <= 0) {
            return;
        }
        $base = ($u->pro_until && $u->pro_until->isFuture()) ? $u->pro_until : now();
        $u->forceFill(['pro_until' => $base->copy()->addDays($days)])->save();
    }

    public function addCoins(User $u, int $n, string $kind = 'admin'): void
    {
        if ($n <= 0) {
            return;
        }
        $u->increment('coins', $n);
        CoinTransaction::create(['user_id' => $u->id, 'kind' => $kind, 'amount' => $n]);
    }

    public function spendCoins(User $u, int $n, string $kind = 'unlock'): bool
    {
        if ($n <= 0) {
            return true;
        }
        if ((int) $u->coins < $n) {
            return false;
        }
        $u->decrement('coins', $n);
        CoinTransaction::create(['user_id' => $u->id, 'kind' => $kind, 'amount' => -$n]);

        return true;
    }

    // ---- Earn (daily check-in / watch-reward) --------------------------

    /**
     * Earn coins from a capped daily activity. Coin amount + daily cap come from
     * the admin config; the cap is enforced by counting today's ledger rows of
     * that kind, so it can't be farmed by spamming the endpoint.
     *
     * @return array{ok:bool,error?:string,earned?:int,remaining?:?int}
     */
    public function earn(User $u, string $kind): array
    {
        $earn = $this->config()['earn'];

        // kind => [coins, daily cap (0 = unlimited), "cap reached" message]
        $rules = [
            'daily' => [(int) ($earn['daily_checkin_coins'] ?? 0), 1, 'วันนี้เช็คอินไปแล้ว'],
            'watch' => [(int) ($earn['watch_reward_coins'] ?? 0), (int) ($earn['watch_reward_daily_cap'] ?? 0), 'รับรางวัลครบตามจำนวนของวันนี้แล้ว'],
            'like' => [(int) ($earn['like_coins'] ?? 0), (int) ($earn['like_daily_cap'] ?? 0), 'รับเหรียญจากไลก์ครบวันนี้แล้ว'],
            'comment' => [(int) ($earn['comment_coins'] ?? 0), (int) ($earn['comment_daily_cap'] ?? 0), 'รับเหรียญจากคอมเมนต์ครบวันนี้แล้ว'],
            'share' => [(int) ($earn['share_coins'] ?? 0), (int) ($earn['share_daily_cap'] ?? 0), 'รับเหรียญจากแชร์ครบวันนี้แล้ว'],
        ];

        if (! isset($rules[$kind])) {
            return ['ok' => false, 'error' => 'ไม่รู้จักกิจกรรมนี้'];
        }

        [$amt, $cap, $capMsg] = $rules[$kind];
        $done = $this->earnedToday($u, $kind);
        if ($cap > 0 && $done >= $cap) {
            return ['ok' => false, 'error' => $capMsg];
        }

        $this->addCoins($u, $amt, $kind);

        return ['ok' => true, 'earned' => $amt, 'remaining' => $cap > 0 ? max(0, $cap - $done - 1) : null];
    }

    private function earnedToday(User $u, string $kind): int
    {
        return CoinTransaction::where('user_id', $u->id)->where('kind', $kind)
            ->whereDate('created_at', today())->count();
    }

    // ---- Episode gating (free / coin-unlock / Pro) ---------------------

    /** free | pro | unlocked | locked */
    public function episodeAccess(User $u, Episode $ep): string
    {
        $cfg = $this->config();

        if ($this->isPro($u) && ($cfg['pro']['unlocks_all'] ?? true)) {
            return 'pro';
        }
        if ((int) $ep->number <= (int) $cfg['free_episodes']) {
            return 'free';
        }
        if (EpisodeUnlock::where('user_id', $u->id)->where('episode_id', $ep->id)->exists()) {
            return 'unlocked';
        }

        return 'locked';
    }

    /**
     * Spend coins to unlock a locked episode (no-op if already playable).
     * @return array{ok:bool,access?:string,error?:string,need?:int,have?:int,spent?:int}
     */
    public function unlockEpisode(User $u, Episode $ep): array
    {
        $access = $this->episodeAccess($u, $ep);
        if ($access !== 'locked') {
            return ['ok' => true, 'access' => $access];
        }

        $cost = (int) $this->config()['unlock_cost_coins'];
        if (! $this->spendCoins($u, $cost, 'unlock')) {
            return ['ok' => false, 'error' => 'เหรียญไม่พอ', 'need' => $cost, 'have' => (int) $u->coins];
        }

        EpisodeUnlock::firstOrCreate(['user_id' => $u->id, 'episode_id' => $ep->id]);

        return ['ok' => true, 'access' => 'unlocked', 'spent' => $cost];
    }

    // ---- Referral ------------------------------------------------------

    /** This user's own referral code, generated on first use. */
    public function ensureCode(User $u): string
    {
        if (! $u->referral_code) {
            do {
                $code = strtoupper(Str::random(7));
            } while (User::where('referral_code', $code)->exists());
            $u->forceFill(['referral_code' => $code])->save();
        }

        return $u->referral_code;
    }

    /**
     * Redeem a friend's code: grants the promo to both sides per config.
     * @return array{ok:bool,error?:string}
     */
    public function redeem(User $u, string $code): array
    {
        $cfg = $this->config()['referral'];

        if (! ($cfg['enabled'] ?? true)) {
            return ['ok' => false, 'error' => 'โปรโมชันโค้ดแนะนำปิดใช้งานชั่วคราว'];
        }
        if ($u->referred_by) {
            return ['ok' => false, 'error' => 'คุณใช้โค้ดแนะนำไปแล้ว'];
        }

        $code = strtoupper(trim($code));
        $referrer = $code !== '' ? User::where('referral_code', $code)->first() : null;

        if (! $referrer) {
            return ['ok' => false, 'error' => 'ไม่พบโค้ดแนะนำนี้'];
        }
        if ($referrer->id === $u->id) {
            return ['ok' => false, 'error' => 'ใช้โค้ดของตัวเองไม่ได้'];
        }

        $max = (int) ($cfg['max_referrals'] ?? 0);
        if ($max > 0 && User::where('referred_by', $referrer->id)->count() >= $max) {
            return ['ok' => false, 'error' => 'โค้ดนี้ถูกใช้ครบจำนวนแล้ว'];
        }

        $u->forceFill(['referred_by' => $referrer->id])->save();
        $this->grantProDays($u, (int) ($cfg['referee_pro_days'] ?? 0));
        $this->addCoins($u, (int) ($cfg['referee_coins'] ?? 0), 'referral');
        $this->grantProDays($referrer, (int) ($cfg['referrer_pro_days'] ?? 0));
        $this->addCoins($referrer, (int) ($cfg['referrer_coins'] ?? 0), 'referral');

        return ['ok' => true];
    }

    // ---- Serialised state for the API / views --------------------------

    /** Everything the app/web needs to render membership for one user. */
    public function state(User $u): array
    {
        return [
            'is_pro' => $this->isPro($u),
            'plan' => $u->plan ?? 'free',
            'pro_until' => optional($u->pro_until)->toIso8601String(),
            'coins' => (int) $u->coins,
            'referral_code' => $this->ensureCode($u),
            'referred' => $u->referred_by !== null,
            'referrals_count' => User::where('referred_by', $u->id)->count(),
            'daily_checkin_available' => $this->earnedToday($u, 'daily') === 0,
        ];
    }
}
