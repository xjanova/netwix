<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give `contents.source_key` its NULL back.
 *
 * Widening it to 400 (2026_08_03_130000) used `$table->string('source_key', 400)->change()`, and
 * `->change()` re-states the WHOLE column definition rather than amending it — so leaving `nullable()`
 * off silently turned a nullable column NOT NULL. `contents.source_key` has been nullable since it was
 * added (2026_07_02_100001) because not every title comes from a source: anything the admin creates by
 * hand has no remote key, and inserting one has been failing outright since that migration ran.
 *
 * `source_titles.source_key` is deliberately NOT touched — that one has been NOT NULL from the start
 * (2026_07_02_100002) and correctly so, since a synced title is nothing without its remote key.
 *
 * No data was harmed in between: every one of the 23,384 rows was import-created and already carried a
 * key, so MySQL's NULL→'' coercion had nothing to coerce. Verified on production before writing this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('source_key', 400)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Deliberately empty. The previous state was a mistake, and re-imposing NOT NULL on a column
        // that legitimately holds NULLs would fail on any hand-made title.
    }
};
