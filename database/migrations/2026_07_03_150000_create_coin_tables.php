<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger of every coin change — also drives the daily/earn caps.
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);   // signup | referral | daily | watch | unlock | admin
            $table->integer('amount');    // + earn, - spend
            $table->timestamps();
            $table->index(['user_id', 'kind', 'created_at']);
        });

        // Episodes a member has permanently unlocked with coins.
        Schema::create('episode_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'episode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('episode_unlocks');
        Schema::dropIfExists('coin_transactions');
    }
};
