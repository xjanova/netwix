<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cache of the remote catalogues (rongyok / wow-drama). Admins sync a source into this
     * table, then browse it and import selected titles into `contents`.
     */
    public function up(): void
    {
        Schema::create('source_titles', function (Blueprint $table) {
            $table->id();
            $table->string('source');                 // rongyok | wowdrama
            $table->string('source_key');             // remote id / slug
            $table->string('title');
            $table->string('clean_title')->nullable();
            $table->text('description')->nullable();
            $table->text('poster_url')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('dub_type')->nullable();   // thai_dub | thai_sub | null
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedInteger('episodes_count')->nullable();
            $table->json('extra')->nullable();        // slug, jpg_url, etc.
            $table->foreignId('content_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_key']);
            $table->index(['source', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_titles');
    }
};
