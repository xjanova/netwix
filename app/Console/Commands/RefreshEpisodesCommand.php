<?php

namespace App\Console\Commands;

use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-scrape the episode list of already-imported, multi-episode-capable titles and refresh their
 * episodes in place — so a series that gained new episodes at the source (dramas/anime air weekly)
 * doesn't stay frozen at its first-import count, and a title the source now flags as a series (but
 * we first imported as a 1-episode "movie") gets corrected. The re-import is idempotent: it upserts
 * episodes, PRESERVES the current publish state + genres + maturity, and fixes movie↔series
 * mis-typing via auto_type (the source's own is_movie flag).
 *
 * Which titles are "refreshable" (can legitimately gain episodes):
 *   - wowdrama         → all (long-form CN/KR/JP series)
 *   - 24hdx / anime108 → only where the source flags is_movie=false (a series; this also catches the
 *                        1-episode "movie" that is really a series, e.g. The Evil Lawyer)
 *   - rongyok          → only with --include-rongyok (short verticals arrive complete; low value)
 *   - 9nung            → never (single-video movies — can't gain episodes)
 *
 * Movies never gain episodes, so they're skipped to spare the source sites needless scraping.
 * See [[NetWix — airing series stuck at old episode count (auto-import never re-imports)]].
 */
class RefreshEpisodesCommand extends Command
{
    protected $signature = 'netwix:refresh-episodes
        {--source= : limit to one source (24hdx|anime108|wowdrama|rongyok)}
        {--limit=0 : max titles this run (0 = no cap)}
        {--airing-only : only recently-imported series without an "ended" marker (for the nightly job)}
        {--include-rongyok : also refresh rongyok verticals (off by default)}
        {--recent-days=60 : with --airing-only, how recent an import still counts as airing}
        {--sleep=250 : ms to pause between titles (politeness to the source sites)}';

    protected $description = 'Refresh episode lists of imported series (catch newly-aired episodes + fix movie→series mis-typing).';

    /** Raw-title markers that a series has finished — used by --airing-only to skip completed titles. */
    private const ENDED_MARKERS = ['จบ', 'ครบทุกตอน', 'END'];

    public function handle(ImportService $importer): int
    {
        $only = $this->option('source');
        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;

        $q = SourceTitle::query()->imported()
            ->when($only, fn ($w) => $w->where('source', $only))
            ->where(function ($w) {
                // wowdrama: every title is a long-form series.
                $w->where('source', 'wowdrama')
                    // Halim sources: only titles the source itself flags as a series.
                    ->orWhere(fn ($x) => $x->whereIn('source', ['24hdx', 'anime108'])
                        ->whereRaw("JSON_EXTRACT(extra, '$.is_movie') = false"));
                if ($this->option('include-rongyok')) {
                    $w->orWhere('source', 'rongyok');
                }
            });

        if ($this->option('airing-only')) {
            $days = max(1, (int) $this->option('recent-days'));
            $q->whereHas('content', fn ($w) => $w->where('created_at', '>=', now()->subDays($days)));
            foreach (self::ENDED_MARKERS as $m) {
                $q->where('title', 'not like', '%'.$m.'%');
            }
            // Least-recently-touched first → the nightly run rotates through the pool over time.
            $q->orderBy('updated_at');
        } else {
            $q->orderByDesc('view_count')->orderBy('id');
        }

        if ($limit > 0) {
            $q->limit($limit);
        }

        // Materialise up front (rows are small) so the custom order + --limit are honoured and no DB
        // cursor is held open across the hours of sequential network scraping. content is eager-loaded.
        $titles = $q->with('content')->get();
        $total = $titles->count();
        if ($total === 0) {
            $this->info('No refreshable titles matched.');

            return self::SUCCESS;
        }
        $this->info("Refreshing episodes for {$total} title(s)…");
        $bar = $this->output->createProgressBar($total);

        $scanned = 0;
        $gained = 0;          // titles that gained episodes
        $gainedEps = 0;       // total episodes added
        $retyped = 0;         // titles whose type was corrected (movie→series)
        $failed = 0;
        $samples = [];

        foreach ($titles as $st) {
            $content = $st->content;
            if (! $content) {
                $bar->advance();

                continue;
            }
            $before = $content->episodes()->count();
            $typeBefore = $content->type;
            try {
                // auto_type = fix movie↔series from current source metadata; publish preserved
                // (hidden_sources still force-unpublish inside import). No 'genres' key → genres
                // are left untouched. No 'maturity' → existing rating preserved.
                $fresh = $importer->import($st, [
                    'auto_type' => true,
                    'publish' => (bool) $content->is_published,
                ]);
                $after = $fresh->episodes()->count();
                $scanned++;
                if ($after > $before) {
                    $gained++;
                    $gainedEps += ($after - $before);
                    if (count($samples) < 12) {
                        $samples[] = "  +{$before}→{$after}  {$st->displayTitle()}";
                    }
                }
                if ($fresh->type !== $typeBefore) {
                    $retyped++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
            $bar->advance();
            if ($sleepUs) {
                usleep($sleepUs);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Scanned {$scanned} · gained episodes on {$gained} title(s) (+{$gainedEps} eps) · re-typed {$retyped}".($failed ? " · {$failed} failed" : '').'.');
        if ($samples) {
            $this->line('Sample gains:');
            foreach ($samples as $s) {
                $this->line($s);
            }
        }

        return self::SUCCESS;
    }
}
