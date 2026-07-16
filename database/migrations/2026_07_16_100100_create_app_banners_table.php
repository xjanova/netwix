<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-controlled promo banners shown at the top of the mobile app's home
 * screen (campaigns like "สมัครใหม่รับฟรีโปร 1 เดือน"). Creative is an uploaded
 * image (public disk, WebP) or an external URL — same convention as AdCampaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120)->nullable();
            $table->string('image_path', 512)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->boolean('hide_for_pro')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_banners');
    }
};
