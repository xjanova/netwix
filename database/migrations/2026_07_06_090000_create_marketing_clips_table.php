<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing clips = short cuts of a title/episode, produced server-side by ffmpeg
 * (on the CLI queue — fpm can't spawn ffmpeg), then auto-posted to Facebook with an
 * AI caption + "download the app" CTA. This table is the single source of truth for a
 * clip's whole lifecycle: pending → processing → ready → scheduled → posted (or failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_clips', function (Blueprint $table) {
            $table->id();

            // What we cut from. content_id is always set; episode_id is null for a
            // movie (single-episode) or when cutting from the title's main video.
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();

            // The cut window, in seconds from the start of the source video.
            $table->unsignedInteger('start')->default(0);
            $table->unsignedSmallInteger('duration')->default(30);
            $table->string('aspect', 8)->default('9:16');   // 9:16 (Reels) | 1:1 (feed) | 16:9

            // Lifecycle. pending → processing → ready → posted; failed on error.
            $table->string('status', 16)->default('pending')->index();
            $table->string('error', 64)->nullable();         // last failure status code (ClipMaker)

            // Produced assets (public disk). file_path = the mp4; poster_path = a webp still.
            $table->string('file_path')->nullable();
            $table->string('poster_path')->nullable();
            $table->unsignedInteger('file_size')->nullable();

            // Marketing payload.
            $table->text('caption')->nullable();             // AI or hand-written FB caption
            $table->string('platform', 16)->default('facebook');
            $table->timestamp('scheduled_at')->nullable()->index();  // when to auto-post (null = manual)
            $table->timestamp('posted_at')->nullable();
            $table->string('remote_post_id')->nullable();    // FB post/video id once published

            // Ops. batch_id groups a "make 5 clips" run so the live UI can track a set.
            $table->string('batch_id', 20)->nullable()->index();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_clips');
    }
};
