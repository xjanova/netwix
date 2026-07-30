<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `contents.rating` and `contents.match_score` were filled by random_int() at import time
 * (ImportService), so all 23k titles carried a made-up score that the site displayed as
 * fact — "★ 8.7" on the title modal and "94% ตรงใจ" on every card. The give-away: not one
 * row fell outside the generator's 7.8–9.6 window.
 *
 * Both columns become nullable and every existing value is cleared. Ratings now come from
 * the `ratings` table (real member stars, 1–5) and a title nobody has rated shows nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->decimal('rating', 3, 1)->nullable()->default(null)->change();
            $table->unsignedTinyInteger('match_score')->nullable()->default(null)->change();
        });

        DB::table('contents')->update(['rating' => null, 'match_score' => null]);
    }

    public function down(): void
    {
        // The old values were invented, so there is nothing meaningful to restore —
        // only the column shape goes back.
        Schema::table('contents', function (Blueprint $table) {
            $table->decimal('rating', 3, 1)->default(8.5)->change();
            $table->unsignedTinyInteger('match_score')->default(95)->change();
        });
    }
};
