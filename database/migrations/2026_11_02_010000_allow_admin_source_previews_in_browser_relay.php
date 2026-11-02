<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_relay_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('episode_id')->nullable()->change();
            $table->foreignId('source_title_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('browser_relay_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_title_id');
        });
        // Keep episode_id nullable: preview history can outlive a code rollback.
    }
};
