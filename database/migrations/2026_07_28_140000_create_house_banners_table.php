<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOUSE BANNERS — the site's own uploaded creatives, shown in the same slots as the network ads.
 * Owner (2026-07-28): "ระบบโฆษณาสำรอง คือแบนเนอร์เราเอง แบบวน หรือสุ่ม หรือกำหนดความถี่ในการแสดงเป็น
 * เปอร์เซ็นต์ได้ เราอัพโหลดภาพเอง".
 *
 * They exist because an ad network can be absent for a long time — not yet approved, rejected, or
 * simply unfilled for a slot — and an empty slot earns nothing. A house banner fills it. Selection
 * (cycle / random / weighted) lives in [App\Models\HouseBanner::pickFor]; how often they take a slot
 * that a network COULD have filled is the `house_ads_fill` setting.
 *
 * Distinct from `app_banners` (the app home screen's promo strip) and `ad_campaigns` (pre-roll on the
 * player): same idea, different surface, and each has its own scheduling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_banners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            // Which placement this creative is for; "all" makes it eligible everywhere.
            $table->string('slot', 16)->default('all');
            $table->string('image_path')->nullable();   // uploaded (public disk, WebP via ImageStore)
            $table->string('image_url')->nullable();    // or an external image
            $table->string('link_url', 2048)->nullable();
            // Relative share in weighted mode. Not a percentage of the whole: shares are normalised
            // against the other eligible banners, so adding one never forces the others to be re-tuned.
            $table->unsignedSmallInteger('weight')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['slot', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_banners');
    }
};
