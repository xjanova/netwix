<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import history — one row per import operation so the admin has a complete, auditable record of what
 * was pulled into the catalogue, from where, by whom, and how it went. Written by the import entry
 * points (manual bulk / admin auto-loop / the scheduled netwix:auto-import command).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40)->index();          // 24hdx, wowdrama, …
            $table->string('action', 20)->default('manual'); // manual | auto | scheduled | backfill
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // admin who ran it (null = cron)
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
