<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_activity', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 16);
            $t->string('subject', 64);
            $t->date('day');
            $t->string('category', 24)->nullable();
            $t->unsignedInteger('seconds')->default(0);
            $t->unique(['user_id', 'kind', 'subject', 'day']);
            $t->index(['user_id', 'day', 'kind']);
        });
        Schema::create('reward_watch_sessions', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $t->foreignId('content_id')->constrained()->cascadeOnDelete();
            $t->foreignId('episode_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64);
            $t->date('day');
            $t->double('position')->default(0);
            $t->timestamp('last_beat_at');
        });
        Schema::create('reward_campaign_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('campaign', 32);
            $t->string('period', 16);
            $t->unsignedInteger('coins');
            $t->timestamp('completed_at');
            $t->unique(['user_id', 'campaign', 'period'], 'reward_campaign_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_campaign_completions');
        Schema::dropIfExists('reward_watch_sessions');
        Schema::dropIfExists('reward_activity');
    }
};
