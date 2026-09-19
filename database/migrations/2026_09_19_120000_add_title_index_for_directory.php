<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The A–Z directory filters on `title LIKE 'x%'` and orders by title, over ~20,000 published rows.
 * Without an index that is a full scan plus a filesort on every group page — cheap enough once, and
 * not cheap when a crawler walks 68 groups and their pages back to back, which is exactly what the
 * directory is built to invite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->index('title', 'contents_title_index');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('contents_title_index');
        });
    }
};
