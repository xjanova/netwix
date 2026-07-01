<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->enum('type', ['series', 'movie', 'vertical'])->default('series');
            $table->text('synopsis')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('maturity', 8)->default('13+');   // 7+ / 13+ / 16+ / 18+
            $table->unsignedTinyInteger('match_score')->default(95); // % ตรงใจ
            $table->decimal('rating', 3, 1)->default(8.5);   // 0-10
            $table->boolean('is_original')->default(false);  // NETWIX ORIGINAL badge
            $table->boolean('is_featured')->default(false);  // hero
            $table->boolean('is_published')->default(true);
            $table->string('poster_path')->nullable();       // 2:3
            $table->string('backdrop_path')->nullable();     // 16:9
            $table->string('trailer_youtube_id')->nullable();
            $table->string('video_url')->nullable();         // movie / vertical single source (mp4/HLS/YouTube)
            $table->unsignedSmallInteger('duration_minutes')->nullable(); // movies
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['type', 'is_published']);
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
