<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Members who were 'premium' only because of the old column default (see the previous migration).
 * Nobody chose or paid for that plan, so there is nothing to tell a default from a grant — the owner
 * decided (2026-09-23) to treat all of them the same: one signup-promo window of Pro counted from
 * today, then basic like everyone else. Admins keep their plan. A member already holding a longer
 * pro_until (bought with gold/USDT, or a referral grant) keeps the longer one.
 */
return new class extends Migration
{
    private const PROMO_DAYS = 30;   // "โปรเราคือแบบ 1 เดือน" — the signup promo's length

    public function up(): void
    {
        $until = now()->addDays(self::PROMO_DAYS);

        DB::table('users')
            ->whereIn('plan', ['standard', 'premium'])
            ->where('role', '!=', 'admin')
            ->orderBy('id')
            ->get(['id', 'pro_until'])
            ->each(function ($user) use ($until) {
                $current = $user->pro_until ? \Illuminate\Support\Carbon::parse($user->pro_until) : null;

                DB::table('users')->where('id', $user->id)->update([
                    'plan' => 'basic',
                    'pro_until' => ($current && $current->greaterThan($until)) ? $current : $until,
                ]);
            });
    }

    public function down(): void
    {
        // One-way: which members were 'premium' is not recorded anywhere after this runs.
    }
};
