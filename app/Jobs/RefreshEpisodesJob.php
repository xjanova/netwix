<?php

namespace App\Jobs;

use App\Services\Import\EpisodeRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Post-sync episode refresh: after a catalogue sync finishes ([SyncCatalogJob] dispatches this on
 * the same `sync` queue), re-scrape the episode lists of that source's still-airing imported series
 * — so one "ซิงค์แคตตาล็อก" click brings in both the new titles AND the new episodes of titles we
 * already have. Bounded (default 120 titles, least-recently-touched first, so consecutive syncs
 * rotate through the pool) to always fit the sync worker's --max-time window; the nightly
 * `netwix:refresh-episodes --airing-only` covers whatever a single run doesn't reach.
 *
 * Best-effort by design: a broken title never fails the job, and sources with nothing refreshable
 * (rongyok, 9nung, pure-movie sets) fall through instantly with an empty selection.
 */
class RefreshEpisodesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Stay under the sync worker's --timeout=650 (routes/console.php). */
    public int $timeout = 550;

    /** Skipping a beat is fine — the nightly command catches up; never silently re-run. */
    public int $tries = 1;

    public function __construct(public string $source, public int $limit = 120) {}

    /** Single-flight per source, independent of the sync mutex. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('refresh-eps:'.$this->source))->dontRelease()->expireAfter(900)];
    }

    public function handle(EpisodeRefresher $refresher): void
    {
        $titles = $refresher->airingOnly($refresher->query($this->source))
            ->limit(max(1, $this->limit))->with('content')->get();

        $gained = 0;
        $eps = 0;
        foreach ($titles as $st) {
            try {
                $r = $refresher->refresh($st);
                if ($r['after'] > $r['before']) {
                    $gained++;
                    $eps += $r['after'] - $r['before'];
                }
            } catch (\Throwable) {
                // best-effort — move on to the next title
            }
            usleep(200_000);   // politeness to the source site
        }

        // Surface the gains on the sync status line (the admin may still have the page open —
        // syncProgress keeps serving this message; harmless if nobody is looking).
        if ($gained > 0) {
            $key = 'sync:'.$this->source;
            $msg = (string) Cache::get("{$key}:message", '');
            Cache::put(
                "{$key}:message",
                trim($msg.($msg !== '' ? ' · ' : '')."อัปเดตตอนซีรีส์ {$gained} เรื่อง (+{$eps} ตอน)"),
                now()->addHours(2),
            );
        }
    }
}
