<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger of every GOLD change — mirrors coin_transactions (silver). Drives the
     * per-day conversion cap and gives admin an auditable money trail. `meta` keeps
     * the source reference (usdt order id, content id, silver spent on a convert…).
     */
    public function up(): void
    {
        Schema::create('gold_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);   // purchase | convert | unlock_vip | buy_pro | admin | refund
            $table->bigInteger('amount');  // + credit, - spend
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gold_transactions');
    }
};
