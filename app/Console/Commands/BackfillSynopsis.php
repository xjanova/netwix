<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Services\Import\RemoteSeries;
use App\Services\Import\SourceRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfills plot synopses for titles already imported from a source that hides them behind a detail
 * page (e.g. 24-hdx — the WP REST feed has no synopsis). Fills BOTH source_titles.description and
 * the imported content's synopsis. Most-viewed first, so popular titles get theirs first.
 *
 *   php artisan netwix:backfill-synopsis 24hdx --limit=100000
 */
class BackfillSynopsis extends Command
{
    protected $signature = 'netwix:backfill-synopsis {source} {--limit=100000}';

    protected $description = 'Scrape + fill missing plot synopses for an import source';

    public function handle(SourceRegistry $registry): int
    {
        $sourceId = (string) $this->argument('source');
        $source = $registry->get($sourceId);
        if (! $source instanceof ProvidesSynopsis) {
            $this->error("Source [{$sourceId}] does not provide synopses.");

            return self::FAILURE;
        }

        $ids = SourceTitle::where('source', $sourceId)
            ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
            ->orderByDesc('view_count')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        $total = $ids->count();
        $ok = 0;
        $miss = 0;
        $this->info("Backfilling synopsis for {$total} {$sourceId} titles…");

        foreach ($ids as $id) {
            $st = SourceTitle::find($id);
            if (! $st) {
                continue;
            }
            try {
                $rs = new RemoteSeries(
                    source: $st->source,
                    sourceKey: $st->source_key,
                    title: $st->title,
                    cleanTitle: $st->displayTitle(),
                    extra: $st->extra ?? [],
                );
                $syn = $source->fetchSynopsis($rs);
            } catch (Throwable $e) {
                $syn = null;
            }

            if (filled($syn)) {
                $st->forceFill(['description' => $syn])->save();
                if ($st->content_id) {
                    Content::whereKey($st->content_id)->update(['synopsis' => $syn]);
                }
                $ok++;
            } else {
                $miss++;
            }

            if (($ok + $miss) % 200 === 0) {
                $this->line('… '.($ok + $miss)."/{$total} · filled {$ok} · none {$miss}");
            }
        }

        $this->info("Done: {$ok} filled, {$miss} without synopsis.");

        return self::SUCCESS;
    }
}
