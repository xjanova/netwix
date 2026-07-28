<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SELF-SERVE AD MARKETPLACE — members buy a banner slot for a number of days and pay in USDT/USDC
 * (BEP20), reusing the receive-only payment service already in place for gold/Pro.
 *
 * Two tables:
 *  - `ad_placements` — what the admin offers for sale: which slot, banner size, price per day, how
 *    many advertisers may share it at once, and the upload ceiling. Priced and capped per placement
 *    so a scarce position can cost more without any code change.
 *  - `ad_bookings` — one purchase. It carries its own review state because a booking is PAID BEFORE
 *    it is approved: the owner's rule is that a rejected ad is not refunded, the customer fixes the
 *    creative and resubmits. That only holds if the rules and the no-refund warning are shown and
 *    acknowledged first, hence `terms_accepted_at` — evidence, on the row, that they were.
 *
 * Screening (see [App\Support\AdScreening]) runs BEFORE checkout and its verdict is kept in
 * `screen_result`, so a later dispute can be answered with what was actually checked and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table) {
            $table->id();
            $table->string('slot', 16);                 // one of App\Support\Ads::SLOTS
            $table->string('name', 120);                // Thai label shown to buyers
            $table->string('blurb', 255)->nullable();   // "เห็นทุกหน้า ใต้เมนูด้านบน"
            $table->unsignedSmallInteger('width')->default(970);
            $table->unsignedSmallInteger('height')->default(250);
            $table->decimal('price_usdt_per_day', 10, 2)->default(1);
            // How many advertisers may hold this placement on the same day. They rotate; each one's
            // share of impressions is 1/max_concurrent, which is what the buyer is quoted.
            $table->unsignedSmallInteger('max_concurrent')->default(3);
            $table->unsignedSmallInteger('max_days')->default(90);
            $table->unsignedInteger('max_upload_kb')->default(600);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });

        Schema::create('ad_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();       // public code the buyer quotes
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_placement_id')->constrained()->cascadeOnDelete();

            $table->string('title', 120)->nullable();
            $table->string('image_path')->nullable();        // always OUR re-encoded copy, never the upload
            $table->string('link_url', 2048);
            $table->string('link_final_url', 2048)->nullable();   // where it actually lands after redirects

            $table->date('starts_at');
            $table->date('ends_at');
            $table->unsignedSmallInteger('days');
            $table->decimal('price_usdt', 10, 2);

            /**
             * draft            — being edited, not yet screened
             * awaiting_payment — screened clean, invoice issued, waiting on the chain
             * paid             — money in, queued for admin review
             * approved         — will run (or is running) inside its window
             * rejected         — admin refused the creative; customer edits and resubmits, no refund
             * expired          — invoice went unpaid
             * finished         — its window has passed
             */
            $table->string('status', 20)->default('draft')->index();
            $table->text('review_note')->nullable();         // why it was rejected, shown to the customer
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('usdt_order_id')->nullable()->constrained('usdt_orders')->nullOnDelete();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->json('screen_result')->nullable();       // what the pre-payment gate saw

            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            // The hot path: "which approved ads run in this placement today".
            $table->index(['ad_placement_id', 'status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_bookings');
        Schema::dropIfExists('ad_placements');
    }
};
