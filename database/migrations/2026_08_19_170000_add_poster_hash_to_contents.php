<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprint of the stored cover image, so the same picture can be recognised on two different
 * titles.
 *
 * Sources have started answering a hotlinked poster request with a house advert instead of the
 * artwork — rongyok serves a green "rongyok.com ดูฟรีเต็มๆ" banner (2026-08-19). A banner is one image
 * reused across every title, so an identical hash on unrelated titles is the signal that gives it
 * away, and it is a signal no amount of re-encoding can hide: our WebP conversion is deterministic,
 * so the same source picture always lands on the same bytes.
 *
 * Deliberately generic rather than a rongyok rule. It catches any placeholder a source decides to
 * serve — "image not found" art, a rebrand banner, a DMCA notice — including ones nobody has seen yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // md5 of the stored image bytes — 32 hex chars, indexed so "how many titles share this
            // picture?" is one cheap query.
            $table->char('poster_hash', 32)->nullable()->index()->after('poster_path');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('poster_hash');
        });
    }
};
