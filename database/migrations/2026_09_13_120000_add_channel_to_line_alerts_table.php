<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts now go to Telegram as well as LINE, and each channel succeeds or fails on its own — a LINE
 * push refused for an exhausted monthly quota says nothing about whether Telegram got through. So
 * every row records which channel it was.
 *
 * The table keeps its old name: renaming it would break the running code for the minutes between
 * the pull and the migrate, and the history must never be the thing that stops an alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('line_alerts', function (Blueprint $table) {
            // Existing rows were all LINE pushes.
            $table->string('channel', 16)->default('line')->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('line_alerts', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn('channel');
        });
    }
};
