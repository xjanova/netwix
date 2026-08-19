<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The control + measurement tables behind the download monitor (/admin/storage).
 *
 * `mirror_jobs` is the CONTROL PLANE: one row per source, holding the state the admin sets from the
 * page (running / paused / stopped) and the counters the worker writes back. The page only ever reads
 * this table, and [App\Console\Commands\MirrorRun] is the only writer of the counters — so a light on
 * the monitor is always a real thing a real process wrote, never a guess. `worker_seen_at` is the
 * heartbeat that lets the page say "you pressed start but nothing is attached" instead of showing a
 * hopeful spinner forever.
 *
 * `mirror_probes` is the MEASUREMENT PLANE. Every storage projection on this site used to be built on
 * one invented average; here each row is a real HTTP measurement of one real episode (Content-Length
 * for MP4, the sum of the manifest's segments for HLS). Nothing is projected from an assumption — if
 * a source has never been probed the page says so and offers the button instead of printing a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mirror_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->unique();
            // idle | queued | running | paused | done | error
            $table->string('status', 16)->default('idle');
            $table->string('scope', 16)->default('missing');   // missing = ตอนที่ยังไม่มีไฟล์ · all
            $table->unsignedInteger('episode_limit')->nullable();   // stop this run after N episodes
            $table->unsignedInteger('done_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);
            $table->unsignedBigInteger('bytes_done')->default(0);
            $table->unsignedBigInteger('last_episode_id')->nullable();
            $table->string('last_title', 190)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('worker_seen_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mirror_probes', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->unsignedBigInteger('episode_id')->nullable();
            $table->string('kind', 8)->nullable();              // mp4 | hls
            $table->unsignedBigInteger('bytes')->nullable();    // measured size of ONE episode
            $table->unsignedInteger('seconds')->nullable();     // runtime, when the manifest reveals it
            $table->boolean('ok')->default(false);
            $table->string('error', 255)->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->index(['source', 'ok', 'measured_at']);
        });

        // The monitor sums file_size over mirrored episodes on every poll. Without this the query is a
        // full scan of ~405,000 rows.
        Schema::table('episodes', function (Blueprint $table) {
            $table->index('mirrored_at');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropIndex(['mirrored_at']);
        });
        Schema::dropIfExists('mirror_probes');
        Schema::dropIfExists('mirror_jobs');
    }
};
