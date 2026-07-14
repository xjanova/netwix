<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined missions: watch a video (a YouTube id or any direct/internal video URL) for
 * `required_seconds` → earn `reward_amount` of `reward_kind` (silver|gold) coins. `repeat` = once
 * (ever) or daily. Verification/anti-cheat lives in App\Services\MissionService (mission_attempts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->string('video_source', 12)->default('youtube'); // youtube | url
            $table->string('video_ref', 2048);                       // YT id, or a direct video URL
            $table->string('poster', 2048)->nullable();
            $table->unsignedInteger('required_seconds')->default(60);
            $table->string('reward_kind', 8)->default('silver');     // silver | gold
            $table->unsignedInteger('reward_amount')->default(5);
            $table->string('repeat', 8)->default('once');            // once | daily
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
