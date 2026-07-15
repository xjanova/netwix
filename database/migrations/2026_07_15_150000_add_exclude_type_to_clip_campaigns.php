<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ไม่เอาประเภท" — the mirror of content_type, so two campaigns can carve the catalogue into
 * genuinely separate halves.
 *
 * Why this is needed: most anime in this catalogue is stored as type='series' (with the
 * อนิเมะ/การ์ตูน genre on top), so a "series" campaign and a "cartoon" campaign would otherwise
 * fish from an overlapping pool and tease the same shows. `content_type=series` +
 * `exclude_type=anime` gives the series campaign real (non-cartoon) series only.
 *
 * It takes the same values as content_type and resolves them the same way — notably "anime",
 * which is a genre umbrella here, not a type (see ClipCampaignRunner::applyTypeScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_campaigns', function (Blueprint $table) {
            $table->string('exclude_type', 16)->nullable()->after('content_type');
        });
    }

    public function down(): void
    {
        Schema::table('clip_campaigns', function (Blueprint $table) {
            $table->dropColumn('exclude_type');
        });
    }
};
