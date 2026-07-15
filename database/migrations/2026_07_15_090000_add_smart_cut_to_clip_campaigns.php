<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart-cut options for clip campaigns (owner request 2026-07-15):
 *   - start_mode      middle | random — WHERE in the episode the cut begins. "middle" is the
 *                     old deterministic behaviour; "random" picks a fresh spot every post so
 *                     a pinned series doesn't show the same scene twice.
 *   - duration_max    when set (> duration), the actual clip length is randomised in
 *                     [duration, duration_max] per post ("สุ่มความยาว ไม่เกิน X วิ").
 *   - full_episode    post the WHOLE episode instead of a short clip. Cut runs on the
 *                     dedicated single-worker `clips-heavy` lane (a full re-encode is far
 *                     too heavy for the 2-worker clips pool and its 310s timeout).
 *   - episode_pick    first | random | sequential — which EPISODE of the picked title to
 *                     cut from. "sequential" continues from the last episode this campaign
 *                     posted for that title (ep1 → ep2 → …, wraps to ep1), so a pinned
 *                     series can be teased in order night after night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_campaigns', function (Blueprint $table) {
            $table->string('start_mode', 8)->default('middle')->after('duration');
            $table->unsignedSmallInteger('duration_max')->nullable()->after('start_mode');
            $table->boolean('full_episode')->default(false)->after('duration_max');
            $table->string('episode_pick', 12)->default('first')->after('full_episode');
        });
    }

    public function down(): void
    {
        Schema::table('clip_campaigns', function (Blueprint $table) {
            $table->dropColumn(['start_mode', 'duration_max', 'full_episode', 'episode_pick']);
        });
    }
};
