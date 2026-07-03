<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social sign-in (Google / LINE) support. Social accounts have no local
     * password, so `password` becomes nullable and we record the provider.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('provider', 20)->nullable()->after('plan');       // google | line
            $table->string('provider_id')->nullable()->after('provider');    // id from the provider
            $table->string('avatar')->nullable()->after('provider_id');      // profile picture url

            $table->index(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['provider', 'provider_id']);
            $table->dropColumn(['provider', 'provider_id', 'avatar']);
            // password stays nullable on rollback — harmless and avoids failing on
            // rows that were created without one.
        });
    }
};
