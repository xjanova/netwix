<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A live watch attempt — the anti-cheat state. The server accumulates `watched_seconds` from focus-gated
 * heartbeats, crediting only REAL wall-clock time between beats (see MissionService::beat), and awards
 * once it reaches the mission's required_seconds. One row per (user, mission), reset on a fresh start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_beat_at')->nullable();
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'mission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_attempts');
    }
};
