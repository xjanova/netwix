<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Support\MirrorLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill for the mirror rotation: walks the whole catalogue, groups titles that are the SAME film or
 * series imported from different sites, and cross-links each group so every copy becomes a backup for
 * the others ([App\Support\MirrorLinker]). New imports link themselves — this is for everything that
 * was already in the catalogue when the feature shipped, and as a periodic tidy-up.
 *
 * Nothing is merged or deleted: both catalogue rows stay exactly as they are, they just gain links.
 * Run it with --dry-run first to see the size of the change.
 */
class LinkMirrorsCommand extends Command
{
    protected $signature = 'netwix:link-mirrors
        {--dry-run : report what would be linked without writing anything}
        {--type= : limit to one content type (movie|series|vertical)}
        {--show= : print the first N duplicate groups (default 15)}';

    protected $description = 'Cross-link duplicate titles across sources so they back each other up.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $show = (int) ($this->option('show') ?: 15);

        $groups = $this->duplicateGroups((string) ($this->option('type') ?: ''));
        if ($groups->isEmpty()) {
            $this->info('No cross-source duplicates found — nothing to link.');

            return self::SUCCESS;
        }

        $this->info($groups->count().' duplicate group(s) found'.($dry ? ' (dry run — nothing written)' : '').':');

        $shown = 0;
        $created = 0;
        foreach ($groups as $group) {
            if ($shown < $show) {
                $sites = $group->map(fn (Content $c) => $c->source)->implode(' + ');
                $this->line(sprintf('  %-52s %s', mb_strimwidth($group->first()->title, 0, 50, '…'), $sites));
                $shown++;
            }
            if (! $dry) {
                $created += MirrorLinker::linkGroup($group);
            }
        }

        if ($shown < $groups->count()) {
            $this->line('  … and '.($groups->count() - $shown).' more');
        }

        $this->info($dry
            ? 'Dry run: re-run without --dry-run to write the links.'
            : "Done — {$created} mirror link(s) created.");

        return self::SUCCESS;
    }

    /**
     * Titles sharing a dedupe key across at least two DIFFERENT sources. The keys that HAVE duplicates
     * are found in SQL (indexed) so only those rows are hydrated — the catalogue is far too big to pull
     * whole. Adult titles are included, hence withoutGlobalScopes: a kids-profile scope must not decide
     * which links exist.
     *
     * @return Collection<int,Collection<int,Content>>
     */
    private function duplicateGroups(string $type): Collection
    {
        $base = fn () => Content::withoutGlobalScopes()
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->whereNotNull('source_key')
            ->whereNotNull('dedupe_key')
            ->where('dedupe_key', '!=', '');

        $dupKeys = $base()
            ->selectRaw('dedupe_key, type, COUNT(DISTINCT source) AS sites')
            ->groupBy('dedupe_key', 'type')
            ->havingRaw('COUNT(DISTINCT source) > 1')
            ->pluck('dedupe_key')
            ->unique()
            ->values();

        if ($dupKeys->isEmpty()) {
            return collect();
        }

        return $base()
            ->whereIn('dedupe_key', $dupKeys)
            ->get(['id', 'title', 'year', 'type', 'source', 'source_key', 'dedupe_key'])
            ->groupBy(fn (Content $c) => $c->type.'|'.$c->dedupe_key)
            ->filter(fn (Collection $g) => $g->pluck('source')->unique()->count() > 1)
            ->values();
    }
}
