<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Support\VerticalGenre;
use Illuminate\Console\Command;

/**
 * Assign a best-effort genre to already-imported titles that have NONE. Sources like wowdrama (WP
 * categories are only country) and 9nung (mostly country-classified) expose no content genre, so titles
 * imported before the guesser existed sit genre-less. This keyword-guesses one from the Thai title +
 * synopsis via [App\Support\VerticalGenre] — the same fallback [ImportService::ensureGuessedGenre] now
 * applies on import. DB-only, no network, re-runnable (skips titles that already have a genre).
 *
 *   php artisan netwix:backfill-genres                 # every genre-less title
 *   php artisan netwix:backfill-genres --source=9nung  # one source
 */
class BackfillGenres extends Command
{
    protected $signature = 'netwix:backfill-genres {--source= : limit to one import source}';

    protected $description = 'Guess + assign a genre to imported titles that have none (keyword from title+synopsis)';

    public function handle(): int
    {
        $base = fn () => Content::doesntHave('genres')
            ->when($this->option('source'), fn ($w, $s) => $w->where('source', $s));

        $total = $base()->count();
        $this->info("Guessing genres for {$total} genre-less titles…");

        $done = 0;
        $base()->select('id', 'title', 'synopsis')->chunkById(300, function ($rows) use (&$done, $total) {
            foreach ($rows as $c) {
                if ($gid = VerticalGenre::guessId(trim($c->title.' '.(string) $c->synopsis))) {
                    $c->genres()->syncWithoutDetaching([$gid => ['is_primary' => true]]);
                    $done++;
                }
            }
            $this->line("… {$done}/{$total}");
        });

        $this->info("Done: {$done} titles got a guessed genre.");

        return self::SUCCESS;
    }
}
