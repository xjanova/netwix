<?php

namespace App\Services;

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

    public function addCoins(User $u, int $n): void
    {
        if ($n > 0) {
            $u->increment('coins', $n);
        }
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
        $this->addCoins($u, (int) ($cfg['referee_coins'] ?? 0));
        $this->grantProDays($referrer, (int) ($cfg['referrer_pro_days'] ?? 0));
        $this->addCoins($referrer, (int) ($cfg['referrer_coins'] ?? 0));

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
        ];
    }
}
