<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who asked for the link and from where, so the BrainX window can say what it is working on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('browser_relay_jobs', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('priority')->constrained('users')->nullOnDelete();
            $table->string('requested_via', 8)->nullable()->after('requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('browser_relay_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('requested_via');
        });
    }
};
