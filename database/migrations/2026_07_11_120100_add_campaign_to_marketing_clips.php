<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a marketing clip back to the campaign that produced it and flag the ones that
 * should auto-post once cut. `post_targets` records which Facebook surfaces (reels/feed)
 * this clip is destined for; `dry_run` marks a clip that was "posted" while Facebook was
 * not connected (simulation), so the admin never mistakes it for a real publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('id')
                ->constrained('clip_campaigns')->nullOnDelete();
            $table->boolean('auto_post')->default(false)->after('platform');
            $table->string('post_targets', 24)->nullable()->after('auto_post'); // CSV reels|feed
            $table->boolean('dry_run')->default(false)->after('remote_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn(['auto_post', 'post_targets', 'dry_run']);
        });
    }
};
