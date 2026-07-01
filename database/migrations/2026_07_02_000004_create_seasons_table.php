<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number')->default(1);
            $table->string('title')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
