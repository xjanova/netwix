<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedInteger('mirror_attempts')->default(0)->after('mirror_trigger');
            $table->timestamp('mirror_failed_at')->nullable()->after('mirror_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['mirror_attempts', 'mirror_failed_at']);
        });
    }
};
