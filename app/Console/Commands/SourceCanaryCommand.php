<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Setting;
use App\Services\Import\SourceRegistry;
use App\Support\SourceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canary for whole-SOURCE outages. Every source gets a handful of its most-watched titles re-resolved;
 * if not one of them comes back with a stream, the source itself is down — not the titles — and that
 * verdict is recorded in [App\Support\SourceHealth].
 *
 * Why this exists: on 2026-07-28 24-hdx put a Cloudflare challenge on its player ajax and every one of
 * its ~6,500 titles stopped resolving at once. Nothing in the system noticed. The per-title machinery
 * ([App\Support\PlaybackHealth]) only learns from viewers hitting failures, so an outage surfaces as a
 * slow drip of unpublished titles instead of one alarm — the same shape as anime108 dying and 9nung
 * migrating its player before it. Cheap, scheduled, source-level probing is the missing layer.
 *
 * Deliberately conservative about calling an outage:
 *  - most-WATCHED titles are probed, because those are the ones known to have played for real;
 *  - a single failure escalates to a wider sample before any verdict, so one genuinely dead film
 *    can't condemn its whole source;
 *  - an exception (network/DNS) is NOT a resolve failure — our own connectivity is the likelier
 *    culprit, and a false "down" would brake auto-suspend across the catalogue for no reason.
 *
 *   php artisan netwix:source-canary            # every visible source
 *   php artisan netwix:source-canary 24hdx      # just one
 */
class SourceCanaryCommand extends Command
{
    protected $signature = 'netwix:source-canary
        {source? : probe only this source id (default: every source that isn\'t hidden)}
        {--titles=3 : titles to probe before a source looks healthy}
        {--confirm=6 : titles to probe in total before declaring it down}
        {--sleep=300 : ms between probes}';

    protected $description = 'Probe each source for a whole-source outage and record it in source_health.';

    public function handle(SourceRegistry $registry): int
    {
        $only = (string) ($this->argument('source') ?? '');
        $sample = max(1, (int) $this->option('titles'));
        $confirm = max($sample, (int) $this->option('confirm'));
        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;

        $hidden = array_filter(array_map('trim', explode(',', (string) Setting::get('hidden_sources', ''))));
        $downNow = [];

        foreach ($registry->all() as $id => $source) {
            if ($only !== '' ? $id !== $only : in_array($id, $hidden, true)) {
                continue;   // a hidden source is already off — probing it tells us nothing useful
            }

            $titles = Content::withoutGlobalScopes()
                ->where('source', $id)
                ->whereNotNull('source_key')
                ->where('is_published', true)
                ->orderByDesc('views')
                ->limit($confirm)
                ->get(['id', 'title', 'source_key', 'type']);

            if ($titles->isEmpty()) {
                $this->line(sprintf('  %-12s no published titles — skipped', $id));

                continue;
            }

            [$ok, $tried, $threw] = $this->probe($source, $titles, $sample, $sleepUs);

            // Our own network wobbled — say nothing rather than brake the whole catalogue on a guess.
            if ($ok === 0 && $threw) {
                $this->warn(sprintf('  %-12s %d/%d resolved but hit network errors — no verdict', $id, $ok, $tried));

                continue;
            }

            SourceHealth::record($id, $ok, $tried);

            if ($ok === 0) {
                $downNow[] = $id;
                $this->error(sprintf('  %-12s DOWN — 0/%d titles resolved', $id, $tried));
            } else {
                $this->line(sprintf('  %-12s ok (%d/%d)', $id, $ok, $tried));
            }
        }

        if ($downNow !== []) {
            // ERROR level so it stands out in the log even though the site itself is serving fine.
            Log::error('source-canary: whole-source outage', ['sources' => $downNow]);
            $this->newLine();
            $this->error('แหล่งที่ล่ม: '.implode(', ', $downNow).' — auto-suspend ของแหล่งนี้ถูกพักไว้แล้ว');
        }

        return self::SUCCESS;
    }

    /**
     * Probe titles until $sample of them resolve. Only when NONE has resolved by then do we keep going
     * to $titles->count() (the --confirm width), so the expensive wide sample is paid for exactly in
     * the case that's about to be called an outage.
     *
     * @param  \Illuminate\Support\Collection<int,Content>  $titles
     * @return array{0:int,1:int,2:bool} [resolved, probed, hitAnException]
     */
    private function probe($source, $titles, int $sample, int $sleepUs): array
    {
        $ok = 0;
        $tried = 0;
        $threw = false;

        foreach ($titles as $c) {
            if ($ok >= $sample || ($ok > 0 && $tried >= $sample)) {
                break;   // it's clearly alive; no need to keep poking the source
            }
            $tried++;
            try {
                // Episode "1" resolves a movie, and is representative for a series (the same remote id
                // serves every episode via its own episode param).
                $stream = $source->resolveByRef((string) $c->source_key, '1');
                if ($stream !== null) {
                    $ok++;
                }
            } catch (Throwable $e) {
                $threw = true;
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        return [$ok, $tried, $threw];
    }
}
