<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per APK download from our own domain (see AppDownloadController::apk).
 * Insert-only, PDPA-safe: version + a coarse platform bucket + member flag, no IP
 * and no user id — the same shape as page_views. The app's OTA updater fetches from
 * GitHub directly, so this counts REAL website downloads only.
 *
 * Unlike page_views this is NOT pruned: the all-time total is the headline figure, and
 * the volume is tiny (at most one row per visitor per version per hour).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_downloads', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40)->index();            // release tag, e.g. "v1.3.0"
            $table->boolean('is_member')->default(false);
            $table->string('platform', 20)->default('other');  // android | other (desktop/iOS visitors)
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_downloads');
    }
};
