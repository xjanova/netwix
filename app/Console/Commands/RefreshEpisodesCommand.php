<?php

namespace App\Console\Commands;

use App\Services\Import\EpisodeRefresher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-scrape the episode list of already-imported, multi-episode-capable titles and refresh their
 * episodes in place — so a series that gained new episodes at the source (dramas/anime air weekly)
 * doesn't stay frozen at its first-import count, and a title the source now flags as a series (but
 * we first imported as a 1-episode "movie") gets corrected. Selection + per-title refresh live in
 * [App\Services\Import\EpisodeRefresher] (shared with the admin "รีเฟรชตอน" button and the
 * post-sync [App\Jobs\RefreshEpisodesJob]).
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

    public function handle(EpisodeRefresher $refresher): int
    {
        $limit = (int) $this->option('limit');
        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;

        $q = $refresher->query($this->option('source'), (bool) $this->option('include-rongyok'));
        if ($this->option('airing-only')) {
            $refresher->airingOnly($q, (int) $this->option('recent-days'));
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
            if (! $st->content) {
                $bar->advance();

                continue;
            }
            try {
                $r = $refresher->refresh($st);
                $scanned++;
                if ($r['after'] > $r['before']) {
                    $gained++;
                    $gainedEps += ($r['after'] - $r['before']);
                    if (count($samples) < 12) {
                        $samples[] = "  +{$r['before']}→{$r['after']}  {$r['title']}";
                    }
                }
                if ($r['retyped']) {
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
