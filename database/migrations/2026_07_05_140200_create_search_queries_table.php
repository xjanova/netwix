<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per intentful on-site search (the /search results page, not the type-ahead). Powers the
 * "content gap" report — what people search for that we have few/zero results for → import targets.
 * PDPA note: stores the normalised term + result count + a guest/member flag only; NO user id, NO IP,
 * so a term can't be tied back to a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('term', 191)->index();
            $table->unsignedInteger('results')->default(0);
            $table->boolean('is_member')->default(false);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
