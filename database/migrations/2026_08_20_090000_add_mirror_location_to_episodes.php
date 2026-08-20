<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember WHERE a mirrored file actually went, instead of recomputing it later.
 *
 * `MediaMirror::delete()` used to rebuild the path from `content.source` / `source_key` /
 * `episode.number` at delete time. That is fine only while those three never change and there is only
 * ever one disk. Neither holds now:
 *
 *   - `netwix:refresh-episodes` runs nightly and re-imports can renumber episodes or rewrite
 *     `source_key`, after which the reconstructed path points at nothing and the real file is orphaned.
 *   - With Cloudflare R2 in the picture, a file may not be on local disk at all. Deleting the local
 *     path would return success (the `public` disk has `throw => false`) while the object stayed in
 *     the bucket, billing every month, with the row's `file_size` already nulled — so it would not even
 *     show up in the usage total.
 *
 * Storing the disk and the exact key makes delete exact and makes a half-migrated catalogue safe:
 * older episodes keep serving from local disk while new ones go to R2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('mirror_disk', 16)->nullable()->after('file_size');
            $table->string('mirror_key', 255)->nullable()->after('mirror_disk');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['mirror_disk', 'mirror_key']);
        });
    }
};
