<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A USDT (BEP20 / BSC) payment order — buy gold or Pro with real crypto.
     *
     * How it stays un-spoofable ("ตัดยอดถูกต้อง ไม่โดนปั๊ม"):
     *  - `amount_usdt` is the base price + a tiny per-order random offset, so two
     *    people buying the same thing get DIFFERENT amounts → the on-chain watcher
     *    can attribute a deposit to exactly one order.
     *  - `tx_hash` is UNIQUE → one blockchain transfer can only ever settle one
     *    order (hard replay guard; a captured/re-sent tx can't be reused).
     *  - a deposit only settles an order created BEFORE it and confirmed enough
     *    times (min_confirmations) — an old/colliding transfer can't claim it.
     * The server only ever RECEIVES to `wallet`; it holds no private key.
     */
    public function up(): void
    {
        Schema::create('usdt_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();          // public order id (shown to the user)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 10);                       // gold | pro
            $table->string('status', 12)->default('pending');    // pending | paid | expired | cancelled
            $table->string('wallet', 64);                        // receiving address (snapshot at create time)
            $table->decimal('base_usdt', 18, 6);                 // nominal price
            $table->decimal('amount_usdt', 18, 6);               // EXACT amount to send (base + unique offset)
            $table->unsignedBigInteger('credited_gold')->default(0); // gold to grant on payment (purpose=gold)
            $table->unsignedInteger('pro_days')->default(0);     // Pro days to grant on payment (purpose=pro)
            $table->string('tx_hash', 80)->nullable()->unique(); // settling on-chain tx (replay guard)
            $table->string('from_address', 64)->nullable();      // payer address (recorded on settle)
            $table->unsignedInteger('confirmations')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('amount_usdt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usdt_orders');
    }
};
