<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate dividend attribution on the coin ledger: which downline generated a
 * dividend (`from_user_id`) and at which level (1 = direct downline). Lets the
 * team view show "coins earned from each level".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('from_user_id')->nullable()->after('kind');
            $table->unsignedTinyInteger('level')->nullable()->after('from_user_id');
            $table->index(['user_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::table('coin_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'level']);
            $table->dropColumn(['from_user_id', 'level']);
        });
    }
};
