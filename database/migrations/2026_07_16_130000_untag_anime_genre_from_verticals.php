<?php

use App\Models\Content;
use App\Models\Genre;
use App\Support\VerticalGenre;
use Illuminate\Database\Migrations\Migration;

/**
 * Data cleanup (owner 2026-07-16): a handful of rongyok VERTICAL short-dramas (live-action, e.g.
 * "ทนายหย่าร้างอยากหย่า") were mis-tagged with the อนิเมะ/การ์ตูน umbrella genre. Because "มาแรง" ranks
 * by view count and these shorts have views while freshly-imported anime sit at 0, they swept the top
 * of the /anime page and buried real anime.
 *
 * The BrowseController + HeroBillboard code now also excludes type=vertical from every anime surface
 * (the future-proof guard). This strips the wrong umbrella genre off the existing rows and re-guesses a
 * real genre so each short stays reachable on /vertical. Idempotent; a no-op on a DB with no such rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $animeIds = Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
        if ($animeIds === []) {
            return;
        }

        $verticals = Content::where('type', 'vertical')
            ->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $animeIds))
            ->get();

        foreach ($verticals as $c) {
            $c->genres()->detach($animeIds);
            // A genre-less vertical shows in no /vertical row (those rails skip the anime genre and need
            // a real one), so re-guess a genre from the title — same guesser import uses. guessId has a
            // fallback genre, so this never leaves it orphaned.
            if (! $c->genres()->exists()
                && ($gid = VerticalGenre::guessId(trim($c->title.' '.(string) $c->synopsis)))) {
                $c->genres()->syncWithoutDetaching([$gid => ['is_primary' => true]]);
            }
        }
    }

    public function down(): void
    {
        // One-way data correction — the previous (wrong) genre tags aren't restored.
    }
};
