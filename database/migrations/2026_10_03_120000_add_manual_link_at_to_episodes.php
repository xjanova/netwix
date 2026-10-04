<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a video_url an admin typed in by hand (/admin/contents/{id}/edit → "ลิงก์วิดีโอ"). video_url is
 * shared with the mirror and the ep1-preview jobs, so without this flag a later mirror run would
 * download over the admin's link, and an unmirror would null it out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->timestamp('manual_link_at')->nullable()->after('video_url');
        });

        // Links admins already typed in through "เพิ่มตอนใหม่". Every job that writes video_url (mirror,
        // ingest, ep1 preview) also stamps mirrored_at, so a video_url without it was entered by hand.
        DB::table('episodes')
            ->whereNotNull('video_url')
            ->whereNull('mirrored_at')
            ->update(['manual_link_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn('manual_link_at');
        });
    }
};
