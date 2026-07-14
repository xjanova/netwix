<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rewarded completion — the ledger + the cap. For a `once` mission there's at most one row per
 * (user, mission); for `daily`, at most one per (user, mission, day). `day` is the local date the
 * reward was granted, so the daily cap is a simple exists() check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('reward_kind', 8);
            $table->unsignedInteger('reward_amount');
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['user_id', 'mission_id', 'day']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_completions');
    }
};
