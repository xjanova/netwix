<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\PlaybackHealth;
use Illuminate\Console\Command;
use Throwable;

/**
 * Undo auto-suspension for titles that play again.
 *
 * [App\Support\PlaybackHealth] takes un-playable titles down one viewer at a time, and its brake
 * against a WHOLE-source outage depends on [App\Support\SourceHealth] being fresh. When the scheduler
 * died on 2026-08-06 that verdict froze at "every source is fine", so goseries4k going dark on
 * 2026-08-12 cost 114 titles before anyone noticed. Fixing the resolver brings the source back — but
 * nothing brought the titles back with it. `netwix:find-backups` only re-sources from OTHER pool
 * sites, so a title whose own source recovered was never reconsidered.
 *
 * This is that missing step: re-resolve each auto-suspended title exactly the way playback does and
 * republish the ones that now return a real proxyable stream. Titles that still fail are left down —
 * this only ever un-does a suspension, never creates one.
 *
 *   php artisan netwix:restore-suspended goseries4k          # one source
 *   php artisan netwix:restore-suspended --dry-run           # every source, report only
 */
class RestoreSuspended extends Command
{
    protected $signature = 'netwix:restore-suspended
        {source? : only this source id (default: every source with suspended titles)}
        {--reason= : only titles suspended with this suspend_reason (e.g. all_links_failed)}
        {--limit=2000 : max titles to check this run}
        {--sleep=300 : ms between titles (be polite to the source)}
        {--dry-run : report what would be republished, change nothing}';

    protected $description = 'Republish auto-suspended titles whose source resolves again';

    public function handle(SourceRegistry $registry): int
    {
        $sleepUs = max(0, (int) $this->option('sleep')) * 1000;
        $dry = (bool) $this->option('dry-run');

        // withoutGlobalScopes: a suspended 18+ title must be reconsidered too, and the CLI has no
        // profile for MaturityScope to read.
        $targets = Content::withoutGlobalScopes()
            ->whereNotNull('suspended_at')
            ->whereNotNull('source')
            ->when($this->argument('source'), fn ($q, $s) => $q->where('source', $s))
            ->when($this->option('reason'), fn ($q, $r) => $q->where('suspend_reason', $r))
            ->orderByDesc('views')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($targets->isEmpty()) {
            $this->info('No suspended titles match.');

            return self::SUCCESS;
        }
        $this->info("Re-checking {$targets->count()} suspended titles…".($dry ? ' (dry run)' : ''));

        $back = 0;
        $stillDown = 0;
        foreach ($targets as $content) {
            $source = $registry->get((string) $content->source);
            if (! $source) {
                $stillDown++;   // source retired/hidden — leave the title where it is
                continue;
            }

            // Resolve through the first episode's own ref, which is what the player uses; a movie's
            // ref is its source_key. Anything short of a proxyable hls/mp4 is not a working title.
            $ref = (string) ($content->episodes()->orderBy('sort')->orderBy('number')->value('source_ref')
                ?: $content->source_key);
            try {
                $stream = $source->resolveByRef((string) $content->source_key, $ref);
            } catch (Throwable) {
                $stream = null;
            }

            if ($stream && in_array($stream->kind, [RemoteStream::KIND_HLS, RemoteStream::KIND_MP4], true)) {
                $back++;
                $this->line("  ✔ {$content->id} {$content->title}");
                if (! $dry) {
                    // republish() (not a bare is_published flip) also clears suspended_at, the fail
                    // tally and the review flag, and opens the grace window — otherwise the title is
                    // live but still sitting in the admin's suspended queue.
                    PlaybackHealth::republish($content);
                }
            } else {
                $stillDown++;
            }

            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->info(($dry ? "Would republish {$back}" : "Republished {$back}")." · {$stillDown} still un-playable.");

        return self::SUCCESS;
    }
}
