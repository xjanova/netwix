<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Support\PosterBackfill;
use Illuminate\Console\Command;

/**
 * Heals titles whose cover is missing or whose hotlinked poster has gone dead: re-fetches a fresh
 * poster from the source and stores it locally (see [App\Support\PosterBackfill]). Whatever stays
 * unresolved is covered by the branded fallback the card renders. Owner rule 2026-07-16.
 *
 *   php artisan netwix:backfill-posters                 # only the truly-missing covers (fast)
 *   php artisan netwix:backfill-posters --check         # + re-check hotlinks, heal the dead ones
 *   php artisan netwix:backfill-posters --source=anime108 --limit=500
 */
class BackfillPosters extends Command
{
    protected $signature = 'netwix:backfill-posters
        {--check : also verify existing hotlinked posters and re-fetch the dead ones}
        {--source= : limit to one import source}
        {--limit=300 : max titles to process this run}
        {--sleep=250 : ms to pause between titles (be polite to the source)}';

    protected $description = 'Re-fetch + locally store covers for titles whose poster is missing or dead';

    public function handle(PosterBackfill $backfill): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $source = $this->option('source') ?: null;

        // withoutGlobalScopes: this is maintenance over the WHOLE catalogue (incl. adult/hidden),
        // not a viewer-scoped browse query.
        $base = fn () => Content::withoutGlobalScopes()
            ->when($source, fn ($q) => $q->where('source', $source));

        // Always target the truly-missing covers (poster_path null/empty).
        $targets = $base()
            ->where(fn ($q) => $q->whereNull('poster_path')->orWhere('poster_path', ''))
            ->limit($limit)->get();

        // With --check, top up from hotlinked posters whose URL no longer loads a real image.
        if ($this->option('check') && $targets->count() < $limit) {
            $need = $limit - $targets->count();
            $seen = $targets->pluck('id')->all();
            $sampled = $base()->where('poster_path', 'like', 'http%')
                ->whereNotIn('id', $seen)
                ->inRandomOrder()->limit($need * 5)->get();
            foreach ($sampled as $c) {
                if ($targets->count() >= $limit) {
                    break;
                }
                if (! $backfill->urlAlive($c->poster_path)) {
                    $targets->push($c);
                }
            }
        }

        $total = $targets->count();
        if ($total === 0) {
            $this->info('No missing/dead posters found.');

            return self::SUCCESS;
        }
        $this->info("Backfilling posters for {$total} titles…");

        $fixed = 0;
        $unresolved = 0;
        foreach ($targets as $c) {
            $path = $backfill->recover($c);
            if ($path !== null) {
                $updates = ['poster_path' => $path];
                if (blank($c->backdrop_path)) {
                    $updates['backdrop_path'] = $path;   // also seed the backdrop if it's empty
                }
                $c->forceFill($updates)->save();
                $fixed++;
            } else {
                $unresolved++;   // no source poster → the branded fallback cover shows on the card
            }

            if (($fixed + $unresolved) % 50 === 0) {
                $this->line('… '.($fixed + $unresolved)."/{$total} · fixed {$fixed} · fallback {$unresolved}");
            }
            if ($sleepMs) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Done: {$fixed} covers restored, {$unresolved} left to the fallback cover.");

        return self::SUCCESS;
    }
}
