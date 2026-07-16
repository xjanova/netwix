<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anonymous device statistics reported by the app on launch (POST /api/app/telemetry)
 * — one row per install, upserted by the app-generated `device_key`. Used only for
 * the admin analytics screen (platform / model / version breakdowns). Collection is
 * disclosed in the privacy policy; no precise location, contacts or identifiers
 * beyond the random install key are ever collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_key', 64)->unique();      // random id generated on-device
            $table->string('platform', 16)->nullable()->index();
            $table->string('os_version', 48)->nullable();
            $table->string('device_model', 96)->nullable();
            $table->string('app_version', 24)->nullable()->index();
            $table->string('locale', 12)->nullable();
            $table->string('screen', 24)->nullable();        // e.g. 1080x2400
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('launches')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_devices');
    }
};
