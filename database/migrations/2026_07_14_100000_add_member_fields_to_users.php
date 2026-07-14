<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer member accounts: an active/suspended switch (an inactive user is blocked at login and kicked
 * mid-session), plus optional contact fields the member can fill in themselves (phone/address — never
 * required). Email already exists (the login) and stays admin-only to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
            $table->string('phone', 32)->nullable()->after('is_active');
            $table->string('address', 500)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'phone', 'address']);
        });
    }
};
