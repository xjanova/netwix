<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * varchar(255) was not wide enough for a Thai slug.
 *
 * WordPress stores a Thai post_name percent-encoded, so every Thai character costs NINE characters
 * ("พ" → "%e0%b8%9e"). A ~30-character Thai title is therefore a ~270-character slug. wow-drama has
 * two of them, and they aborted a 2,340-title catalogue sync 65% of the way through with
 * "1406 Data too long for column 'source_key'".
 *
 * 400 rather than 512: `source_key` is the second column of a UNIQUE index on (source, source_key),
 * and InnoDB caps an index key at 3072 bytes. With utf8mb4 at 4 bytes/char, (255 + 512) * 4 = 3068
 * clears it by four bytes, which is not a margin. (255 + 400) * 4 = 2620 leaves real room, and 400
 * is still 45% above the longest slug either site actually publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_titles', function (Blueprint $table) {
            $table->string('source_key', 400)->change();
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->string('source_key', 400)->change();
        });
    }

    public function down(): void
    {
        // Only safe while nothing longer than 255 has been stored; MySQL refuses (or truncates)
        // otherwise, which is the correct outcome — narrowing must not silently corrupt keys.
        Schema::table('source_titles', function (Blueprint $table) {
            $table->string('source_key', 255)->change();
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->string('source_key', 255)->change();
        });
    }
};
