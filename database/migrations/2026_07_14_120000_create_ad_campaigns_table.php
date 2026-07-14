<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined PRE-ROLL ad campaigns: shown on the player before the real video starts. A creative is
 * a still image OR a video (an uploaded file, a direct mp4/m3u8 URL, or a YouTube link) plus an optional
 * caption + click-through link. `skippable`/`skip_after` control the skip button; `image_seconds` is how
 * long a still is shown. Targeting: `all` videos, one content `type` (movie|series|vertical), or one
 * `genre`. `starts_at`/`ends_at` bound the campaign window. `hide_for_pro` drops the ad for Pro members.
 * Picking + eligibility live in App\Models\AdCampaign::pickFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('media_type', 8)->default('image');   // image | video
            $table->string('media_path', 2048)->nullable();      // uploaded file (relative, public disk)
            $table->string('media_url', 2048)->nullable();        // external image/video/YouTube URL
            $table->string('caption', 500)->nullable();
            $table->string('link_url', 2048)->nullable();         // optional click-through
            $table->boolean('skippable')->default(true);
            $table->unsignedSmallInteger('skip_after')->default(5);    // seconds before the Skip button
            $table->unsignedSmallInteger('image_seconds')->default(8);  // still-image display duration
            $table->string('target', 8)->default('all');          // all | type | genre
            $table->string('target_type', 12)->nullable();        // movie | series | vertical
            $table->foreignId('target_genre_id')->nullable()->constrained('genres')->nullOnDelete();
            $table->string('frequency', 8)->default('always');    // always | session | daily
            $table->boolean('hide_for_pro')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort')->default(0);          // higher = shown first among eligible
            $table->timestamps();
            $table->index(['is_active', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
