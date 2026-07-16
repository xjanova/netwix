<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\Genre;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\ProvidesGenres;
use App\Services\Import\RemoteSeries;
use App\Services\Import\SourceRegistry;
use Illuminate\Console\Command;

/**
 * Backfill REAL sub-genres for an already-imported source whose catalogue feed omits them but whose
 * title pages carry genre tags (e.g. animeruka — Dooplay). Assigns the scraped genres ON TOP of the
 * umbrella (syncWithoutDetaching) so the /anime per-genre browse rows aren't empty. Also stores them on
 * the source title's extra.genre_names so a re-import keeps them. Gentle + re-runnable.
 *
 *   php artisan netwix:scrape-genres animeruka --limit=2000 --sleep=200
 */
class ScrapeGenres extends Command
{
    protected $signature = 'netwix:scrape-genres {source} {--limit=100000} {--sleep=200 : ms between titles} {--force : re-scrape even titles that already have a sub-genre}';

    protected $description = 'Scrape + assign real sub-genres to imported titles from a source that provides them';

    public function handle(SourceRegistry $registry): int
    {
        $sid = (string) $this->argument('source');
        $source = $registry->get($sid);
        if (! $source instanceof ProvidesGenres) {
            $this->error("Source [{$sid}] doesn't provide scrapeable genres.");

            return self::FAILURE;
        }

        $umbrella = Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all();
        $idByName = Genre::pluck('id', 'name');
        $sleepMs = max(0, (int) $this->option('sleep'));

        $titles = SourceTitle::where('source', $sid)->whereNotNull('content_id')
            ->orderByDesc('view_count')->limit((int) $this->option('limit'))->get();

        $total = $titles->count();
        $this->info("Scraping genres for {$total} {$sid} titles…");
        $done = 0;
        $miss = 0;

        foreach ($titles as $st) {
            $content = Content::withoutGlobalScopes()->find($st->content_id);
            if (! $content) {
                continue;
            }
            // Skip a title that already has a NON-umbrella genre unless --force.
            if (! $this->option('force')
                && $content->genres()->whereNotIn('genres.id', $umbrella ?: [0])->exists()) {
                continue;
            }

            try {
                $names = $source->fetchGenres(new RemoteSeries(
                    source: $sid, sourceKey: $st->source_key,
                    title: $st->title, cleanTitle: $st->displayTitle(), extra: is_array($st->extra) ? $st->extra : [],
                ));
            } catch (\Throwable) {
                $names = [];
            }

            $ids = collect($names)->map(fn ($n) => $idByName[$n] ?? null)->filter()->unique()->values()->all();
            if ($ids !== []) {
                $content->genres()->syncWithoutDetaching($ids);
                // Remember for a future re-import (umbrella first, then the scraped genres).
                $st->forceFill(['extra' => array_merge((array) ($st->extra ?? []), [
                    'genre_names' => array_values(array_unique(array_merge(['อนิเมะ'], $names))),
                ])])->save();
                $done++;
            } else {
                $miss++;
            }

            if (($done + $miss) % 100 === 0) {
                $this->line("… {$done}+{$miss}/{$total} · assigned {$done}");
            }
            if ($sleepMs) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Done: {$done} titles got real sub-genres, {$miss} had none to map.");

        return self::SUCCESS;
    }
}
