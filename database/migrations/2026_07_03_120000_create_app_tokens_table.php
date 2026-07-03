<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opaque bearer tokens for the mobile app. Deliberately NOT Sanctum (the box has
 * no local composer; we keep new deps to a minimum). A token is a random string;
 * only its sha256 is stored, so a DB leak can't be replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();          // device label
            $table->string('token_hash', 64)->unique();  // sha256 hex of the plaintext
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_tokens');
    }
};
