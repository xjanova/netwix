<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every alert we push to LINE.
 *
 * Written because the owner got an alert on their phone and asked what it was — and nothing on the
 * server could answer. The throttle key is stored as `line:alert:<sha1>` in the cache, so it cannot be
 * read back; the message body was never kept anywhere; and a successful push logged nothing at all
 * (only failures reached the log). The alert existed on the phone and nowhere else.
 *
 * An alerting system you cannot audit after the fact is only half an alerting system: "was that real,
 * and has it happened before?" is exactly the question an alert provokes, and answering it needs
 * history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_alerts', function (Blueprint $table) {
            $table->id();
            // The un-hashed throttle key ('source-down:24hdx', 'digest', 'test'…) — what the cache
            // deliberately cannot tell us later.
            $table->string('alert_key', 120)->nullable()->index();
            $table->text('body');
            $table->boolean('ok')->default(false);
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_alerts');
    }
};
