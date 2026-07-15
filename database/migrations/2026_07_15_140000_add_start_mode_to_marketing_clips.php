<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-clip cut intent (owner request 2026-07-15: "5 นาทีสุดท้ายของแต่ละตอน").
 *
 *   absolute — `start` is the second to cut from (every existing clip).
 *   ending   — cut the LAST `duration` seconds instead; `start` is ignored and the real
 *              window is resolved by ClipMaker from the media itself (HLS playlist sum /
 *              ffmpeg probe), because contents.duration_minutes is often absent or rounded
 *              and "the last 5 minutes" has to land exactly on the cliffhanger.
 *
 * This lives on the CLIP (not just the campaign) so the cutter knows the intent at the
 * moment it has the real duration in hand — the scheduler never sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->string('start_mode', 10)->default('absolute')->after('start');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->dropColumn('start_mode');
        });
    }
};
