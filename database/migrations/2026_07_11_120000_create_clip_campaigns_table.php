<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clip marketing campaigns (Phase 3). A campaign is a standing rule that, on its own
 * schedule, auto-picks a title from the catalogue, cuts a marketing clip from it, and
 * posts that clip to the NetWix Facebook page (Reels / feed) with an AI caption + app CTA.
 *
 * Modelled on the Fortune Bot content-campaign system:
 *   - `clip_campaigns`      — one row per campaign (selection filter + schedule + clip params).
 *   - `clip_campaign_posts` — one row per (campaign, date, slot) run. The UNIQUE
 *     (campaign_id, post_date, slot_time) key is the hard double-post guard: the every-5-min
 *     publisher can fire more than once inside a slot window and never post twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_enabled')->default(false)->index();

            // ── Title selection ─────────────────────────────────────────────
            // Any of these narrow the pool; all null = "the whole catalogue".
            $table->string('content_type', 16)->nullable();   // movie | series | anime | vertical
            $table->foreignId('genre_id')->nullable()->constrained('genres')->nullOnDelete();
            $table->string('source', 32)->nullable();          // restrict to one source (e.g. rongyok)
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete(); // fix one title
            $table->string('pick', 12)->default('trending');   // trending | random | newest
            $table->boolean('include_adult')->default(false);  // keep 18+/20+ off Facebook by default
            $table->unsignedSmallInteger('avoid_recent_days')->default(14); // don't repost same title within N days

            // ── Clip parameters ─────────────────────────────────────────────
            $table->unsignedSmallInteger('duration')->default(45); // seconds
            $table->string('aspect', 8)->default('9:16');          // 9:16 | 1:1 | 16:9

            // ── Posting ─────────────────────────────────────────────────────
            $table->string('targets', 24)->default('reels,feed'); // CSV of reels|feed

            // ── Schedule ────────────────────────────────────────────────────
            $table->string('days', 20)->default('');   // CSV of weekday 0-6 (0=Sun); empty = every day
            $table->json('slots')->nullable();          // ["09:00","18:30", …] post times

            $table->timestamp('last_run_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('clip_campaign_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('clip_campaigns')->cascadeOnDelete();
            $table->date('post_date');
            $table->string('slot_time', 5);            // "HH:MM"

            // pending → cutting → posted (or failed / skipped). The clip is produced
            // asynchronously on the `clips` queue, so a post sits in `cutting` until the
            // ffmpeg job finishes and PostClipToFacebook publishes it.
            $table->string('status', 12)->default('pending')->index();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->foreignId('marketing_clip_id')->nullable()->constrained('marketing_clips')->nullOnDelete();

            $table->boolean('dry_run')->default(false); // true = FB not connected, nothing really posted
            $table->json('targets_posted')->nullable(); // {"reels":"<id>","feed":"<id>"}
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'post_date', 'slot_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_campaign_posts');
        Schema::dropIfExists('clip_campaigns');
    }
};
