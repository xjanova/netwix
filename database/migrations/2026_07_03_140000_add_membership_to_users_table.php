<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membership / affiliate fields. Pro access can come from a paid `plan`
     * OR a time-limited `pro_until` grant (referral promo, etc). `referral_code`
     * is this user's own code; `referred_by` is who invited them (one-time).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('plan');
            $table->foreignId('referred_by')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
            $table->timestamp('pro_until')->nullable()->after('referred_by');
            $table->unsignedInteger('coins')->default(0)->after('pro_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn(['referral_code', 'pro_until', 'coins']);
        });
    }
};
