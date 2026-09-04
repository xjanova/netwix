<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times one client has earned a ban — the memory that makes a repeat offence punishable.
 *
 * This cannot live in `blocked_ips`, because that table is deliberately transient: a block expires
 * and an admin unblock DELETES the row. Both are correct — the block list means "who is refused
 * right now" — but they leave us unable to answer the only question an escalating penalty depends
 * on: "is this the same person as last time?" After a 6-hour ban ends, the scraper who comes back is
 * indistinguishable from a first-time visitor, and gets the same 6 hours again, forever.
 *
 * So the block is transient and the RECORD is durable, the same split the rest of this system uses
 * (`security_events` keeps the evidence; `blocked_ips` keeps the consequence).
 *
 * Keyed by ScrapeGuard::blockKey() — an exact address for IPv4, the /64 for IPv6 — because that is
 * the unit we mean by "person", and scoring a ladder on a raw IPv6 address would reset every time a
 * Thai carrier rotated the low half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_offences', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 64)->unique();   // wider than blocked_ips: holds a /64 CIDR
            $table->unsignedInteger('offences')->default(0);
            $table->string('last_reason', 24)->nullable();
            $table->timestamp('first_at')->nullable();
            $table->timestamp('last_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_offences');
    }
};
