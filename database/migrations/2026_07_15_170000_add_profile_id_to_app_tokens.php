<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which profile a mobile device is currently watching as.
 *
 * The web keeps this in the session (`session('profile_id')`), but the app is
 * stateless — a bearer token IS its session, so the choice belongs on the token.
 * That also makes the kids gate real: MaturityScope keys off the bound profile,
 * so a kids device cannot reveal adult titles just by omitting a header. NULL =
 * the account's default profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_tokens', function (Blueprint $table) {
            // nullOnDelete: deleting a profile must revoke the choice, never the device's login.
            $table->foreignId('profile_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('app_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_id');
        });
    }
};
