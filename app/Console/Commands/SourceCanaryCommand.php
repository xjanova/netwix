<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Episode;
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
        $verdicts = [];   // source id => [ok, tried], held back until the run is sanity-checked

        foreach ($registry->all() as $id => $source) {
            if ($only !== '' ? $id !== $only : in_array($id, $hidden, true)) {
                continue;   // a hidden source is already off — probing it tells us nothing useful
            }

            $probes = $this->probesFor($id, $confirm);

            if ($probes === []) {
                $this->line(sprintf('  %-12s no published titles — skipped', $id));

                continue;
            }

            [$ok, $tried, $threw] = $this->probe($source, $probes, $sample, $sleepUs);

            // Our own network wobbled — say nothing rather than brake the whole catalogue on a guess.
            if ($ok === 0 && $threw) {
                $this->warn(sprintf('  %-12s %d/%d resolved but hit network errors — no verdict', $id, $ok, $tried));

                continue;
            }

            $verdicts[$id] = [$ok, $tried];

            if ($ok === 0) {
                $downNow[] = $id;
                $this->error(sprintf('  %-12s DOWN — 0/%d titles resolved', $id, $tried));
            } else {
                $this->line(sprintf('  %-12s ok (%d/%d)', $id, $ok, $tried));
            }
        }

        // Sanity rail: independent sites do not fail together. A majority reporting down means the
        // fault is far more likely to be ours — our network, or a bug in this very command, which is
        // precisely what happened on its first run (it probed every source with a hardcoded episode
        // ref). Recording those verdicts would disable auto-suspend across most of the catalogue on
        // the strength of our own bug, so nothing is written and a human is asked to look.
        if (count($downNow) > count($verdicts) / 2 && count($verdicts) > 1) {
            Log::error('source-canary: majority of sources reported down — verdicts discarded as implausible', [
                'down' => $downNow, 'checked' => array_keys($verdicts),
            ]);
            $this->newLine();
            $this->error('มี '.count($downNow).'/'.count($verdicts).' แหล่งรายงานว่าล่มพร้อมกัน — ไม่น่าเป็นไปได้ จึงไม่บันทึกผล (น่าจะเป็นฝั่งเราเอง)');

            return self::FAILURE;
        }

        foreach ($verdicts as $id => [$ok, $tried]) {
            SourceHealth::record($id, $ok, $tried);
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
     * The (key, ref) pairs to probe for one source, most-watched first.
     *
     * The ref MUST be the episode's own stored source_ref, never a hardcoded "1": only the Halim sites
     * take a plain episode number: goseries4k's ref is an episode POST id, animeruka's is an "ep/{id}"
     * path, wowdrama's a numeric episode id. Probing those with "1" resolves nothing and would report
     * a healthy source as a total outage — which is exactly what the first run of this command did,
     * and it would have silently disabled auto-suspend for three working sources.
     *
     * @return array<int,array{key:string,ref:string,title:string}>
     */
    private function probesFor(string $sourceId, int $limit): array
    {
        $titles = Content::withoutGlobalScopes()
            ->where('source', $sourceId)
            ->whereNotNull('source_key')
            ->where('is_published', true)
            ->orderByDesc('views')
            ->limit($limit)
            ->get(['id', 'title', 'source_key']);

        if ($titles->isEmpty()) {
            return [];
        }

        // One episode per title, lowest number — the same key/ref pair the player would resolve.
        $firstEp = Episode::whereIn('content_id', $titles->pluck('id'))
            ->whereNotNull('source_ref')->where('source_ref', '!=', '')
            ->orderBy('number')
            ->get(['content_id', 'source_ref'])
            ->groupBy('content_id');

        $probes = [];
        foreach ($titles as $c) {
            $ref = (string) ($firstEp[$c->id][0]->source_ref ?? '');
            if ($ref !== '') {
                $probes[] = ['key' => (string) $c->source_key, 'ref' => $ref, 'title' => (string) $c->title];
            }
        }

        return $probes;
    }

    /**
     * Probe until $sample of them resolve. Only when NONE has resolved by then do we keep going to the
     * full --confirm width, so the expensive wide sample is paid for exactly in the case that's about
     * to be called an outage.
     *
     * @param  array<int,array{key:string,ref:string,title:string}>  $probes
     * @return array{0:int,1:int,2:bool} [resolved, probed, hitAnException]
     */
    private function probe($source, array $probes, int $sample, int $sleepUs): array
    {
        $ok = 0;
        $tried = 0;
        $threw = false;

        foreach ($probes as $p) {
            if ($ok >= $sample || ($ok > 0 && $tried >= $sample)) {
                break;   // it's clearly alive; no need to keep poking the source
            }
            $tried++;
            try {
                if ($source->resolveByRef($p['key'], $p['ref']) !== null) {
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
