<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-suspend for un-playable titles: when enough distinct viewers can't play a title (dead
 * upstream link / fatal player error), it's unpublished and parked here for an admin to review
 * (re-source or delete). These columns record that state so the admin "หยุดเผยแพร่" list can show
 * why + when, separate from a manual unpublish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->index()->after('is_published');
            $table->string('suspend_reason', 60)->nullable()->after('suspended_at');
            $table->unsignedInteger('playback_fail_count')->default(0)->after('suspend_reason');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspend_reason', 'playback_fail_count']);
        });
    }
};
