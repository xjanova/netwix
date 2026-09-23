<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.plan` defaulted to 'premium' since the first migration, and Membership::isPro() treats
 * 'premium' as Pro — so every signup was permanently Pro: 18+ titles, no ads, VIP via pro_unlocks,
 * none of it paid for. Real Pro is `pro_until` (signup promo, gold, USDT, referral). Owner's call
 * 2026-09-23: new accounts start on 'basic' and get the signup promo like the code always intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('basic')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan')->default('premium')->change();
        });
    }
};
