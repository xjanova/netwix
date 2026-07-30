<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `contents.views` was seeded from the source site's own view counter at import and then
 * overwritten again on every re-sync, so the number on every card was somebody else's
 * traffic: 31,001,146 site-wide against 298,018 real watches, and single titles reading
 * half a million views for a few dozen actual viewers.
 *
 * Every real watch increments `views` AND one of `views_web` / `views_app`
 * (WatchController, app CatalogController), and import never touched those two — so their
 * sum is the only trustworthy record of what NetWix actually served. Rebase `views` onto it.
 *
 * That the sum EXCEEDED `views` on 3,242 rows is the proof the column was being clobbered;
 * this restores those 45,120 lost watches at the same time.
 *
 * Nothing else needs to change: cards, views_label, the "ยอดวิว" sorts, scopeTrending and
 * the landing counter all read `views`, and now it means what they have always claimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE contents SET views = views_web + views_app');
    }

    public function down(): void
    {
        // The old values were another site's counters, so there is nothing to restore.
    }
};
