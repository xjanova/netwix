<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playback markers (intro-skip + credits) — content-level, applied to every episode of the title.
 *   intro_end_seconds — absolute seconds from the start; the "ข้ามอินโทร" button seeks here (and, with
 *                        auto-skip on, jumps here automatically). Doubles as the "start" marker for a
 *                        clip that opens with a title card.
 *   outro_seconds     — length of the end credits in seconds (measured from the END, so ONE value works
 *                        across episodes of differing length): when remaining ≤ this, the player shows
 *                        "เล่นตอนต่อไป" / auto-advances, and on the last episode (or a long movie) pops
 *                        the rate+comment card — so a viewer isn't lost to the credits first.
 * Both nullable/opt-in: null = today's behaviour (advance / card only at the real video end).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->unsignedSmallInteger('intro_end_seconds')->nullable()->after('duration_minutes');
            $table->unsignedSmallInteger('outro_seconds')->nullable()->after('intro_end_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['intro_end_seconds', 'outro_seconds']);
        });
    }
};
