<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * News-ticker announcements shown on the landing hero.
     * Fully editable from the admin panel (admin.announcements.*).
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('badge', 40)->nullable();   // small pill label e.g. "ใหม่", "ร้อนแรง"
            $table->string('body', 300);               // the headline text that scrolls in the ticker
            $table->string('link')->nullable();        // optional destination when the item is clicked
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
