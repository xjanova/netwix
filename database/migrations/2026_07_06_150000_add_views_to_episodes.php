<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-episode view counter. Content.views counts title-level watches; this counts which EPISODE was
 * actually played. Incremented from the real player's first progress ping per viewer+episode/6h
 * (previews never post progress, so they can't inflate it). See InteractionController::progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedBigInteger('views')->default(0)->after('sort');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn('views');
        });
    }
};
