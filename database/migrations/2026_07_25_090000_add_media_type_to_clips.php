<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT a marketing post is made of (owner request 2026-07-25: "ให้เลือกได้ว่าจะโพสต์เป็นคลิป
 * หรือรูปปกของหนัง หรือภาพนิ่งช็อตหนึ่งในวิดีโอ").
 *
 *   clip   — the existing behaviour: an ffmpeg-cut mp4 (Reels/feed video).
 *   poster — no video at all: the title's own cover art, posted as a photo.
 *   frame  — a single still grabbed out of the episode at the campaign's cut position.
 *
 * It lives on BOTH tables for the same reason start_mode does: the campaign holds the
 * standing intent, the clip row records what THIS artifact actually is (a hand-made post
 * has no campaign). Default 'clip' so every existing row and campaign keeps its behaviour.
 *
 * Photos are stored exactly like clips — file_path holds the postable JPEG — so purging,
 * reposting, download and delete all keep working with no special-casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_campaigns', function (Blueprint $table) {
            $table->string('media_type', 10)->default('clip')->after('slug');
        });

        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->string('media_type', 10)->default('clip')->after('episode_id');
        });
    }

    public function down(): void
    {
        Schema::table('clip_campaigns', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });

        Schema::table('marketing_clips', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });
    }
};
