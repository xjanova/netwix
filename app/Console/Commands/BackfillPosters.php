<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Support\PosterBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Heals titles whose cover is missing or whose hotlinked poster has gone dead: re-fetches a fresh
 * poster from the source and stores it locally (see [App\Support\PosterBackfill]). Whatever stays
 * unresolved is covered by the branded fallback the card renders. Owner rule 2026-07-16.
 *
 *   php artisan netwix:backfill-posters                 # only the truly-missing covers (fast)
 *   php artisan netwix:backfill-posters --check         # + re-check hotlinks, heal the dead ones
 *   php artisan netwix:backfill-posters --source=anime108 --limit=500
 *   php artisan netwix:backfill-posters --localize --limit=2000    # cache WORKING hotlinks locally
 */
class BackfillPosters extends Command
{
    protected $signature = 'netwix:backfill-posters
        {--check : also verify existing hotlinked posters and re-fetch the dead ones}
        {--localize : pull every still-hotlinked cover into our own storage, working or not}
        {--source= : limit to one import source}
        {--limit=300 : max titles to process this run}
        {--sleep=250 : ms to pause between titles (be polite to the source)}';

    protected $description = 'Re-fetch + locally store covers for titles whose poster is missing or dead';

    public function handle(PosterBackfill $backfill): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $source = $this->option('source') ?: null;
        $localize = (bool) $this->option('localize');

        // withoutGlobalScopes: this is maintenance over the WHOLE catalogue (incl. adult/hidden),
        // not a viewer-scoped browse query.
        $base = fn () => Content::withoutGlobalScopes()
            ->when($source, fn ($q) => $q->where('source', $source));

        // --localize is the bulk cache sweep: every cover we still serve from someone else's server,
        // most-watched first so the pages people actually open get light and cacheable in the first
        // batch. Resumable by construction — a localized cover no longer matches `http%`, so the next
        // run picks up exactly where this one stopped, and re-running is never wasted work.
        if ($localize) {
            return $this->sweep(
                $base()->where('poster_path', 'like', 'http%')->orderByDesc('views')->limit($limit)->get(),
                fn (Content $c) => $backfill->localize($c),
                $backfill, $sleepMs, 'left hotlinked (source gave us nothing)'
            );
        }

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

        return $this->sweep($targets, fn (Content $c) => $backfill->recover($c), $backfill, $sleepMs,
            'left to the fallback cover');
    }

    /**
     * Walk the targets, storing whatever $heal returns. Shared by both modes so the progress output,
     * the politeness sleep and the "what didn't work" accounting can't drift apart between them.
     *
     * @param  \Illuminate\Support\Collection<int,Content>  $targets
     * @param  callable(Content):?string  $heal
     */
    private function sweep($targets, callable $heal, PosterBackfill $backfill, int $sleepMs, string $missLabel): int
    {
        $total = $targets->count();
        if ($total === 0) {
            $this->info('Nothing to do — no cover matched.');

            return self::SUCCESS;
        }
        $this->info("Storing covers for {$total} titles…");

        $fixed = 0;
        $missed = 0;
        $bytes = 0;
        foreach ($targets as $c) {
            $path = $heal($c);
            if ($path !== null) {
                $backfill->apply($c, $path);
                $fixed++;
                $bytes += (int) (@Storage::disk('public')->size($path) ?: 0);
            } else {
                $missed++;
            }

            if (($fixed + $missed) % 50 === 0) {
                $this->line('… '.($fixed + $missed)."/{$total} · stored {$fixed} · {$missLabel} {$missed}");
            }
            if ($sleepMs) {
                usleep($sleepMs * 1000);
            }
        }

        $avg = $fixed > 0 ? round($bytes / $fixed / 1024) : 0;
        $this->info("Done: {$fixed} covers stored locally (avg {$avg} KB), {$missed} {$missLabel}.");

        return self::SUCCESS;
    }
}
