<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('source')->nullable()->after('slug');      // rongyok | wowdrama | null(manual)
            $table->string('source_key')->nullable()->after('source'); // remote id / slug
            $table->unique(['source', 'source_key']);
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->string('source')->nullable()->after('content_id');
            $table->string('source_ref')->nullable()->after('source'); // rongyok: ep number, wowdrama: wp post id
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropUnique(['source', 'source_key']);
            $table->dropColumn(['source', 'source_key']);
        });
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_ref']);
        });
    }
};
