<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight diagnostics shipped by the mobile app (POST /api/app/debug) so
 * on-device issues — especially sign-in / LINE — can be analysed server-side.
 * Public, rate-limited, size-capped, and pruned; never stores secrets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_debug_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->default('info');   // info | warn | error
            $table->string('event', 80);                     // e.g. auth.line.exchange_fail
            $table->text('message')->nullable();
            $table->json('context')->nullable();             // arbitrary extra (no secrets)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('app_version', 24)->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_debug_logs');
    }
};
