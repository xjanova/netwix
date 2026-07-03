<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-title star ratings (1-5), one per profile. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stars'); // 1-5
            $table->timestamps();
            $table->unique(['content_id', 'profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
