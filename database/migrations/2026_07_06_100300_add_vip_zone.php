<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The VIP zone: titles flagged `is_vip` need gold to watch (or an active Pro).
     * `vip_price_gold` is an optional per-title override of the config default.
     * `vip_unlocks` records a member's permanent gold-unlock of one VIP title.
     */
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('is_featured');
            $table->unsignedInteger('vip_price_gold')->nullable()->after('is_vip');
        });

        Schema::create('vip_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_gold')->default(0); // gold spent (for the audit trail)
            $table->timestamps();
            $table->unique(['user_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vip_unlocks');
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['is_vip', 'vip_price_gold']);
        });
    }
};
