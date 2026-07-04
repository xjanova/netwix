<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // thai_dub | thai_sub | null — the audio/subtitle track, shown as a "พากย์ไทย/ซับไทย" label.
            $table->string('dub_type')->nullable()->after('maturity');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('dub_type');
        });
    }
};
