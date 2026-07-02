<?php

namespace App\Console\Commands;

use App\Models\Genre;
use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use App\Services\Import\SourceRegistry;
use Illuminate\Console\Command;

class ImportContentCommand extends Command
{
    protected $signature = 'netwix:import
        {source : rongyok|wowdrama}
        {--limit=30 : how many titles to import}
        {--type= : content type override (series|movie|vertical)}
        {--genre= : genre slug to assign (default: round-robin across all genres)}
        {--sync : re-sync the catalogue first}
        {--draft : import as draft instead of published}';

    protected $description = 'Sync and import titles from an external source into the NetWix catalogue.';

    public function handle(SourceRegistry $registry, ImportService $importer): int
    {
        $sourceId = $this->argument('source');
        if (! $registry->has($sourceId)) {
            $this->error("Unknown source: {$sourceId}");

            return self::FAILURE;
        }
        $source = $registry->get($sourceId);

        if ($this->option('sync') || SourceTitle::where('source', $sourceId)->count() === 0) {
            $this->info("Syncing {$source->displayName()} catalogue…");
            $n = $importer->sync($sourceId, 30);
            $this->info("  synced {$n} titles.");
        }

        $limit = (int) $this->option('limit');
        $type = $this->option('type') ?: $source->defaultContentType();

        $genres = Genre::orderBy('sort')->get();
        if ($genres->isEmpty()) {
            $this->error('No genres exist — create some first.');

            return self::FAILURE;
        }
        $fixedGenre = $this->option('genre') ? $genres->firstWhere('slug', $this->option('genre')) : null;

        $titles = SourceTitle::where('source', $sourceId)->notImported()
            ->orderByDesc('view_count')->limit($limit)->get();

        if ($titles->isEmpty()) {
            $this->warn('Nothing left to import (all synced titles already imported).');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($titles->count());
        $ok = 0;
        $fail = 0;
        foreach ($titles->values() as $i => $st) {
            $genre = $fixedGenre ?: $genres[$i % $genres->count()]; // spread across genres so rows fill
            try {
                $importer->import($st, [
                    'type' => $type,
                    'genres' => [$genre->id],
                    'primary_genre' => $genre->id,
                    'publish' => ! $this->option('draft'),
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info("Imported {$ok} titles".($fail ? " ({$fail} failed)" : '').'.');

        return self::SUCCESS;
    }
}
