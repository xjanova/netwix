<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-source MIRROR LINKS: every other site that carries the same title, so a dead link can fail
 * over instead of taking the title down. Owner (2026-07-28): "ถ้าเรื่องไหนซ้ำ ให้ทำเป็นลิ้งค์สำรอง
 * ของกันและกัน ถ้าเปิดไม่ได้ก็วนลิ้งค์ต่อไปเรื่อยๆ จนกว่าจะครบรอบ ถือว่าหนังตาย เข้าโหมดหยุดเผยแพร่อัตโนมัติ".
 *
 * Duplicates are paired BOTH ways (A mirrors B and B mirrors A) by [App\Support\MirrorLinker], so
 * whichever copy a viewer opens can play the other's stream. One row per (content, source): a second
 * link on a site that's already in the chain adds nothing.
 *
 * Rotation + the health columns are driven by [App\Support\MirrorRotation]; `episodes.active_mirror_id`
 * remembers which link last worked so the chain doesn't restart at a known-dead primary every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_mirrors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);          // registry id of the site carrying the copy
            $table->string('source_key');          // that site's remote id/slug for the title
            $table->unsignedSmallInteger('priority')->default(0);   // lower = tried earlier
            $table->boolean('is_manual')->default(false);           // admin-pinned → always tried first
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->unsignedInteger('fail_streak')->default(0);     // consecutive failures; reset on success
            $table->timestamps();

            $table->unique(['content_id', 'source']);
            $table->index(['content_id', 'priority']);
        });

        Schema::table('episodes', function (Blueprint $table) {
            // The link that last played this episode: null = start the chain at the title's own source.
            $table->foreignId('active_mirror_id')->nullable()->after('backup_forced')
                ->constrained('content_mirrors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_mirror_id');
        });
        Schema::dropIfExists('content_mirrors');
    }
};
