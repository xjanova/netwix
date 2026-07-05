<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup stream for an un-playable title: when a title is auto-suspended (see App\Support\
 * PlaybackHealth) the netwix:find-backups bot locates a working stream on ANOTHER Halim pool site
 * and records it here, per episode. The resolver ([StreamController]) falls back to these when the
 * primary source can't produce a stream. Nullable — set only for episodes running on a backup.
 *
 * Human-readable site label = SourceRegistry->get(backup_source)->displayName().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('backup_source', 32)->nullable()->after('source_ref');
            $table->string('backup_key')->nullable()->after('backup_source');   // remote id on the backup site
            $table->string('backup_ref', 32)->nullable()->after('backup_key');  // episode ref on the backup site
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['backup_source', 'backup_key', 'backup_ref']);
        });
    }
};
