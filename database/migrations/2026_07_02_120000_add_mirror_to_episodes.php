<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->timestamp('mirrored_at')->nullable()->after('video_url');
            $table->unsignedBigInteger('file_size')->nullable()->after('mirrored_at');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['mirrored_at', 'file_size']);
        });
    }
};
