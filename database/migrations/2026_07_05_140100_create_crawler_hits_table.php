<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per search-engine / AI crawler fetch of a public HTML page (see TrackPageView).
 * Lets the admin SEO dashboard show "is Google/Bing/GPTBot actually finding our pages, and which".
 * PDPA note: bots are not natural persons — this stores only a bot label + path + timestamp, no IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawler_hits', function (Blueprint $table) {
            $table->id();
            $table->string('bot', 32)->index();
            $table->string('path', 191)->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawler_hits');
    }
};
