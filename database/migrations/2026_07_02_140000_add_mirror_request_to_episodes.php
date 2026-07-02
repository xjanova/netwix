<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->timestamp('mirror_requested_at')->nullable()->after('file_size');
            $table->unsignedInteger('mirror_requests')->default(0)->after('mirror_requested_at');
            $table->string('mirror_trigger', 16)->nullable()->after('mirror_requests'); // admin | customer
            $table->index('mirror_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropIndex(['mirror_requested_at']);
            $table->dropColumn(['mirror_requested_at', 'mirror_requests', 'mirror_trigger']);
        });
    }
};
