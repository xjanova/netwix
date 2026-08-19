<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence store for scraping behaviour, plus the blocklist it feeds.
 *
 * We learned the hard way this month that a source can be gone for thirteen days without anyone
 * noticing, purely because nothing was written down. The same blindness applies in the other
 * direction: today we cannot answer "who has been walking our catalogue?" at all, because
 * `crawler_hits` only records self-identifying search bots on HTML pages and deliberately skips
 * /api, /stream and /storage — which is exactly where a scraper works.
 *
 * A competent scraper does not announce itself, so identity is useless and BEHAVIOUR is the signal:
 * request rate, walking ids in order, asking for JSON but never the CSS beside it, minting stream
 * tokens far faster than anyone could watch. Each observation is a row here; the score accumulates
 * per client, and only a client that keeps earning it ends up in `blocked_ips`.
 *
 * On personal data: an IP is kept because blocking is impossible without one, but only for clients
 * that actually tripped a rule — ordinary viewers are never written here — and rows are pruned on a
 * retention window. That is the narrow security use, not analytics; page analytics stay in
 * `page_views`, which stores no IP at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            // Kept in the clear ONLY because a block needs an address to act on.
            $table->string('ip', 45)->index();

            // What tripped: rate | sequential | no_referer | token_abuse | bot_ua | probe
            $table->string('reason', 24)->index();

            // Weight of this single observation. Rules disagree about severity, so the caller decides.
            $table->unsignedSmallInteger('score')->default(1);

            $table->string('method', 8)->nullable();
            $table->string('path', 191)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Free-form supporting detail (ids walked, request count in window, …) — what makes an
            // event re-readable months later instead of just a number.
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable()->index();

            // "What did this address do, most recent first" — the query the admin page lives on.
            $table->index(['ip', 'created_at']);
        });

        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('reason', 24);
            $table->unsignedInteger('score')->default(0);

            // Blocks expire on purpose. A permanent block on a shared or mobile-carrier address ends
            // up hitting a real viewer who was never the scraper.
            $table->timestamp('expires_at')->nullable()->index();

            // Set by an admin, and never cleared automatically.
            $table->boolean('manual')->default(false);

            $table->unsignedInteger('hits')->default(0);   // requests refused since the block began
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('blocked_ips');
    }
};
