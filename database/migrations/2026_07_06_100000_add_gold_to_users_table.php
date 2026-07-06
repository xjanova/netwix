<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gold coins — the PREMIUM currency, separate from silver `coins`.
     *   - silver `coins`  : earned from missions, spends to unlock episodes (existing).
     *   - gold `gold_coins`: bought with USDT (BSC), spends on the VIP zone + Pro.
     * Kept as its own column (not merged into `coins`) so the two economies never
     * bleed into each other — farming silver can only reach gold through the
     * admin-gated conversion in App\Services\GoldWallet.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('gold_coins')->default(0)->after('coins');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gold_coins');
        });
    }
};
