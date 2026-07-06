<?php

namespace App\Services;

use App\Models\Content;
use App\Models\GoldTransaction;
use App\Models\User;
use App\Models\VipUnlock;
use Illuminate\Support\Facades\DB;

/**
 * The GOLD economy — the premium currency you buy with USDT (see UsdtPayment)
 * and spend on the VIP zone + Pro. Silver (App\Services\Membership::$coins) can
 * be converted UP into gold under admin-set limits, but never the other way, so
 * free-farmed silver can't be cashed out. Every mutation is ledgered in
 * gold_transactions and guarded by a row lock so a balance can't be double-spent
 * by two concurrent requests.
 */
class GoldWallet
{
    public function __construct(private Membership $m) {}

    private function cfg(): array
    {
        return $this->m->config();
    }

    // ---- Ledgered credit / debit --------------------------------------

    public function addGold(User $u, int $n, string $kind, array $meta = []): void
    {
        if ($n <= 0) {
            return;
        }
        DB::transaction(function () use ($u, $n, $kind, $meta) {
            $u->increment('gold_coins', $n);
            GoldTransaction::create([
                'user_id' => $u->id,
                'kind' => $kind,
                'amount' => $n,
                'meta' => $meta ?: null,
            ]);
        });
    }

    /** Debit gold if the balance covers it. Row-locked → no double-spend. */
    public function spendGold(User $u, int $n, string $kind, array $meta = []): bool
    {
        if ($n <= 0) {
            return true;
        }

        return DB::transaction(function () use ($u, $n, $kind, $meta) {
            $fresh = User::whereKey($u->id)->lockForUpdate()->first();
            if (! $fresh || (int) $fresh->gold_coins < $n) {
                return false;
            }
            $fresh->decrement('gold_coins', $n);
            GoldTransaction::create([
                'user_id' => $u->id,
                'kind' => $kind,
                'amount' => -$n,
                'meta' => $meta ?: null,
            ]);
            $u->gold_coins = $fresh->gold_coins;   // keep the passed-in model in sync

            return true;
        });
    }

    // ---- Silver → gold conversion -------------------------------------

    /** Gold minted via conversion so far today (drives the daily cap). */
    public function convertedTodayGold(User $u): int
    {
        return (int) GoldTransaction::where('user_id', $u->id)
            ->where('kind', 'convert')
            ->whereDate('created_at', today())
            ->sum('amount');
    }

    /**
     * Convert silver → $goldWanted gold at the admin rate (+ optional fee), capped
     * per day. Spends silver, credits gold, both ledgered.
     *
     * @return array{ok:bool,error?:string,gold?:int,silver_spent?:int,remaining_today?:?int}
     */
    public function convert(User $u, int $goldWanted): array
    {
        $g = $this->cfg()['gold'];

        if (! ($g['convert_enabled'] ?? true)) {
            return ['ok' => false, 'error' => 'ปิดการแปลงเหรียญชั่วคราว'];
        }
        if ($goldWanted < 1) {
            return ['ok' => false, 'error' => 'จำนวนเหรียญทองไม่ถูกต้อง'];
        }

        $cap = (int) ($g['convert_daily_cap'] ?? 0);
        $doneToday = $this->convertedTodayGold($u);
        if ($cap > 0 && $doneToday + $goldWanted > $cap) {
            $left = max(0, $cap - $doneToday);
            return ['ok' => false, 'error' => "วันนี้แปลงได้อีก {$left} เหรียญทอง", 'remaining_today' => $left];
        }

        $rate = max(1, (int) ($g['convert_rate'] ?? 100));
        $fee = max(0, (float) ($g['convert_fee_pct'] ?? 0));
        $silverCost = (int) ceil($goldWanted * $rate * (1 + $fee / 100));

        // Spend silver first (Membership owns the silver ledger). kind 'convert' is
        // not an earn-kind, so it pays no affiliate dividend.
        if (! $this->m->spendCoins($u, $silverCost, 'convert')) {
            return ['ok' => false, 'error' => 'เหรียญเงินไม่พอ', 'need' => $silverCost, 'have' => (int) $u->coins];
        }

        $this->addGold($u, $goldWanted, 'convert', ['silver' => $silverCost, 'rate' => $rate, 'fee_pct' => $fee]);

        return [
            'ok' => true,
            'gold' => $goldWanted,
            'silver_spent' => $silverCost,
            'remaining_today' => $cap > 0 ? max(0, $cap - $doneToday - $goldWanted) : null,
        ];
    }

    // ---- VIP zone -----------------------------------------------------

    /** open (not VIP) | pro | unlocked | locked */
    public function vipAccess(User $u, Content $c): string
    {
        if (! $c->is_vip) {
            return 'open';
        }
        if ($this->m->isPro($u) && ($this->cfg()['vip']['pro_unlocks'] ?? true)) {
            return 'pro';
        }
        if (VipUnlock::where('user_id', $u->id)->where('content_id', $c->id)->exists()) {
            return 'unlocked';
        }

        return 'locked';
    }

    /** Gold price to unlock a VIP title — per-title override, else the config default. */
    public function vipCost(Content $c): int
    {
        return (int) ($c->vip_price_gold ?? ($this->cfg()['vip']['unlock_cost_gold'] ?? 0));
    }

    /**
     * Spend gold to permanently unlock a VIP title (no-op if already watchable).
     * @return array{ok:bool,access?:string,error?:string,need?:int,have?:int,spent?:int}
     */
    public function unlockVip(User $u, Content $c): array
    {
        $access = $this->vipAccess($u, $c);
        if ($access !== 'locked') {
            return ['ok' => true, 'access' => $access];
        }

        $cost = $this->vipCost($c);
        if (! $this->spendGold($u, $cost, 'unlock_vip', ['content_id' => $c->id])) {
            return ['ok' => false, 'error' => 'เหรียญทองไม่พอ', 'need' => $cost, 'have' => (int) $u->gold_coins];
        }

        VipUnlock::firstOrCreate(
            ['user_id' => $u->id, 'content_id' => $c->id],
            ['price_gold' => $cost]
        );

        return ['ok' => true, 'access' => 'unlocked', 'spent' => $cost];
    }

    // ---- Buy Pro with gold (instant, no chain) ------------------------

    /**
     * Spend gold to activate/extend Pro. USDT-paid Pro goes through UsdtPayment;
     * this is the in-app gold path.
     * @return array{ok:bool,error?:string,spent?:int,pro_until?:?string}
     */
    public function buyProWithGold(User $u): array
    {
        $usdt = $this->cfg()['usdt'];
        $price = (int) ($usdt['buy_pro_gold'] ?? 0);
        $days = (int) ($usdt['pro_days'] ?? 30);

        if ($price <= 0) {
            return ['ok' => false, 'error' => 'ยังไม่เปิดให้ซื้อ Pro ด้วยเหรียญทอง'];
        }
        if (! $this->spendGold($u, $price, 'buy_pro', ['days' => $days])) {
            return ['ok' => false, 'error' => 'เหรียญทองไม่พอ', 'need' => $price, 'have' => (int) $u->gold_coins];
        }

        $this->m->grantProDays($u, $days);
        $this->m->distributeProDividend($u);   // affiliate dividend on a real Pro activation

        return ['ok' => true, 'spent' => $price, 'pro_until' => optional($u->fresh()->pro_until)->toIso8601String()];
    }

    // ---- Serialised gold/VIP rules for the API / views ----------------

    /** The gold-economy rules + this user's gold balance & today's convert headroom. */
    public function state(User $u): array
    {
        $g = $this->cfg()['gold'];
        $cap = (int) ($g['convert_daily_cap'] ?? 0);

        return [
            'gold_coins' => (int) $u->gold_coins,
            'per_usdt' => (float) ($g['per_usdt'] ?? 0),
            'convert_enabled' => (bool) ($g['convert_enabled'] ?? false),
            'convert_rate' => (int) ($g['convert_rate'] ?? 100),
            'convert_fee_pct' => (float) ($g['convert_fee_pct'] ?? 0),
            'convert_daily_cap' => $cap,
            'convert_remaining_today' => $cap > 0 ? max(0, $cap - $this->convertedTodayGold($u)) : null,
        ];
    }
}
