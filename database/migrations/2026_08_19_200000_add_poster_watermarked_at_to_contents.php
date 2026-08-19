<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this title's stored cover was branded with our own mark.
 *
 * Needed because branding the back catalogue is a sweep over ~23,600 files that has to be resumable:
 * the box is shared and already fell over once this year under stacked image work, so the command
 * runs in bounded batches and this column is how it remembers where it stopped. It also stops a
 * re-run from stamping the same cover twice, which would double the mark.
 *
 * Cleared whenever a cover is replaced, so a fresh poster gets branded again rather than inheriting
 * the old flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->timestamp('poster_watermarked_at')->nullable()->index()->after('poster_hash');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('poster_watermarked_at');
        });
    }
};
