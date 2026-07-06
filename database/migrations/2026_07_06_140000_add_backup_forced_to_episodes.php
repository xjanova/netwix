<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual "บังคับอัพเดทลิ้งค์" (force-update link) flag. The auto netwix:find-backups bot only fills the
 * backup_* columns as a FALLBACK the resolver reaches when the primary link is dead. When an admin
 * force-applies a chosen pool site's link (see App\Http\Controllers\Admin\ForceLinkController), this
 * flag is set so the resolver plays that backup FIRST — overriding even a still-working primary. The
 * backup_source/key/ref still hold which site + remote id; this only changes the priority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->boolean('backup_forced')->default(false)->after('backup_ref');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn('backup_forced');
        });
    }
};
