<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ข้ามเรื่องที่ตัดไม่ได้" (owner 2026-07-15). A campaign slot used to produce NOTHING when the
 * picked title's source was momentarily un-cuttable (e.g. wowdrama's dead token, a CDN blip):
 * the cut failed → the slot was flagged failed → no post. Now a failed cut makes the runner pick
 * ANOTHER title and try again, up to a bounded number of attempts, so the slot still lands a clip.
 *
 *   attempts          how many titles this slot has already tried to cut.
 *   tried_content_ids the content ids already attempted this slot, so the next pick skips them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_campaign_posts', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->json('tried_content_ids')->nullable()->after('content_id');
        });
    }

    public function down(): void
    {
        Schema::table('clip_campaign_posts', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'tried_content_ids']);
        });
    }
};
