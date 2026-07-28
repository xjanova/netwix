<?php

use App\Models\Content;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist [Content::dedupeKey] so "is this the same title on another site?" is an indexed lookup.
 * It's a normalisation SQL can't reproduce (it strips dub tags, spaces and punctuation), so without a
 * column [App\Support\MirrorLinker] would have to pull the whole catalogue for every imported title.
 * The value is kept in sync by Content's saving hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->after('title')->index();
        });

        // Backfill in chunks — the catalogue is ~15k rows and the key can only be computed in PHP.
        Content::withoutGlobalScopes()->select('id', 'title')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('contents')->where('id', $row->id)
                    ->update(['dedupe_key' => Content::dedupeKey($row->title)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
