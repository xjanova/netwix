<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split the single `views` tally into a web + app breakdown so the admin can see
 * where a title is watched. `views` stays the all-time grand total (incl. the
 * historical, un-attributed views); `views_web` / `views_app` accumulate the
 * platform split from here on, so web + app can be less than the grand total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->unsignedBigInteger('views_web')->default(0)->after('views');
            $table->unsignedBigInteger('views_app')->default(0)->after('views_web');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['views_web', 'views_app']);
        });
    }
};
