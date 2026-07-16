<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-broadcast notifications for the mobile app's in-app inbox. Category
 * drives the user's per-topic mute toggles in the app: new_content (หนังมาใหม่),
 * news (ข่าวจากทีมงาน), other (อื่น ๆ). The app polls GET /api/app/notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20)->default('news')->index(); // new_content | news | other
            $table->string('title', 120);
            $table->text('body');
            $table->string('image_url', 2048)->nullable();
            $table->string('link_url', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
