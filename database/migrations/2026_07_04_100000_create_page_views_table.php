<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight self-hosted traffic log — one row per successful human HTML page view,
 * powering the admin SEO/traffic dashboard. Insert-only (no updated_at); old rows are
 * pruned to ~90 days opportunistically by the TrackPageView middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->string('path', 191)->index();      // URL path, no query string (e.g. "genre/genre-0")
            $table->boolean('is_member')->default(false);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
