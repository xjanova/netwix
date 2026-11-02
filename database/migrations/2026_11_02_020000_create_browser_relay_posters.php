<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_relay_posters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_title_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('series_id', 20);
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('state', 16)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('lease_token', 64)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('available_at');
            $table->string('last_error', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_relay_posters');
    }
};
