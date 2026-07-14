<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded profile picture (per viewing profile, Netflix-style). NULL = fall back to the coloured
 * initial tile (avatar_color). Stored as a relative path to a WebP via App\Support\ImageStore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('avatar_path', 2048)->nullable()->after('avatar_color');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
