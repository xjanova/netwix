<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a coarse traffic-source bucket to page_views (google / facebook / line / direct / …).
 * PDPA note: we store ONLY the bucket derived from the Referer host — never the raw referer URL,
 * never an IP — so a row stays non-identifiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_views', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('is_member');
        });
    }

    public function down(): void
    {
        Schema::table('page_views', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
