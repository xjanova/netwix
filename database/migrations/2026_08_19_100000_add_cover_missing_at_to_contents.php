<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a title whose cover could NOT be recovered automatically, so it lands in the admin's
 * "ปกที่หายไป" queue ([App\Http\Controllers\Admin\CoverController]) instead of silently showing the
 * branded fallback forever.
 *
 * A missing cover is only half-visible in the data today: `poster_path` being null covers the 46
 * titles that never got one, but a title whose HOTLINKED poster went dead still holds a URL that
 * looks perfectly fine in the database — the only judge is a browser that tried to load it. The
 * on-demand heal ([App\Http\Controllers\PosterHealController]) already learns that, then throws the
 * finding away. Recording it here turns those into a work-list a human can actually clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->timestamp('cover_missing_at')->nullable()->index()->after('backdrop_path');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('cover_missing_at');
        });
    }
};
