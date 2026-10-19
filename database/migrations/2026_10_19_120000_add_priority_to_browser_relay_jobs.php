<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Viewer-requested episodes (0) are opened before the next-episode prefetch (1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_relay_jobs', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(0)->after('episode');
            $table->index(['state', 'priority', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('browser_relay_jobs', function (Blueprint $table) {
            $table->dropIndex(['state', 'priority', 'requested_at']);
            $table->dropColumn('priority');
        });
    }
};
