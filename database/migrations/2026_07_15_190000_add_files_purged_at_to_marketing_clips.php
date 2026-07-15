<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "เก็บคลิปไว้ตรวจสอบ 15 วันพอ แล้วลบอัตโนมัติ แต่ประวัติเก็บไว้" (owner 2026-07-15).
 *
 * The heavy mp4 + poster of a marketing clip are only needed briefly (to review + let Facebook
 * fetch them). After a retention window the FILES are deleted to reclaim disk, but the ROW stays
 * — caption, posted_at, remote_post_id, which title/episode — so the history is intact. This
 * column marks when the files were purged (and doubles as the "already purged, skip" guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->timestamp('files_purged_at')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->dropColumn('files_purged_at');
        });
    }
};
