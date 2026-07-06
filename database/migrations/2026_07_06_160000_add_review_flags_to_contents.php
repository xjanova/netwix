<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Link under review" state (PlaybackHealth). review_flagged_at is set when the same viewer fails to
 * play a title 3× (see PlaybackHealth); it shows a ⚠ badge in admin + a "🚧 กำลังตรวจสอบลิงก์" notice
 * on the site (title stays watchable). An admin who verifies the link is fine ticks review_ignored,
 * which clears the flag and blocks re-flagging until they untick it. In DB (not Redis) so cards/pages
 * read it with the already-loaded model and the admin decision is durable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->timestamp('review_flagged_at')->nullable()->after('playback_fail_count');
            $table->boolean('review_ignored')->default(false)->after('review_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['review_flagged_at', 'review_ignored']);
        });
    }
};
