<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-EPISODE playback markers — override the content-level defaults for a single episode (some titles
 * have inconsistent intro/credits lengths across episodes). Same meaning as the content columns
 * (see 2026_07_13_120000_add_playback_markers_to_contents): intro_end_seconds = absolute skip-to point,
 * outro_seconds = credits length from the end. NULL on an episode = inherit the content's value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedSmallInteger('intro_end_seconds')->nullable()->after('duration_minutes');
            $table->unsignedSmallInteger('outro_seconds')->nullable()->after('intro_end_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['intro_end_seconds', 'outro_seconds']);
        });
    }
};
