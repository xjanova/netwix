<?php

namespace App\Services\Import;

use App\Models\Content;
use App\Models\Genre;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\MediaSource;
use Illuminate\Support\Str;

class ImportService
{
    public function __construct(private SourceRegistry $registry) {}

    /**
     * Sync a source's remote catalogue into `source_titles` (upsert). Returns the number synced.
     * Persists per page/batch so a timeout still keeps earlier pages.
     */
    public function sync(string $sourceId, int $maxPages = 100): int
    {
        $source = $this->registry->get($sourceId);
        if (! $source) {
            return 0;
        }

        return $source->fetchCatalog(function (array $batch) use ($sourceId) {
            foreach ($batch as $rs) {
                /** @var RemoteSeries $rs */
                SourceTitle::updateOrCreate(
                    ['source' => $sourceId, 'source_key' => $rs->sourceKey],
                    [
                        'title' => $rs->title,
                        'clean_title' => $rs->cleanTitle,
                        'description' => $rs->description,
                        'poster_url' => $rs->posterUrl,
                        'year' => $rs->year,
                        'dub_type' => $rs->dubType,
                        'view_count' => $rs->viewCount,
                        'extra' => $rs->extra,
                        'synced_at' => now(),
                    ],
                );
            }
        }, $maxPages);
    }

    /**
     * Import one synced title into `contents` (+ episodes + genres). Idempotent — re-importing
     * updates the existing content and its episodes.
     *
     * @param  array{type?:string,genres?:int[],primary_genre?:int|null,publish?:bool,is_original?:bool,auto_type?:bool,auto_genres?:bool}  $opts
     */
    public function import(SourceTitle $st, array $opts = []): Content
    {
        $source = $this->registry->get($st->source);
        if (! $source) {
            throw new \RuntimeException("ไม่รู้จักแหล่งที่มา: {$st->source}");
        }

        $type = $this->resolveType($source, $st, $opts);
        $title = $st->displayTitle();

        $content = Content::updateOrCreate(
            ['source' => $st->source, 'source_key' => $st->source_key],
            [
                'title' => $title,
                'slug' => $this->uniqueSlug($title, $st),
                'type' => $type,
                'synopsis' => $st->description,
                'year' => $st->year,
                'maturity' => '15+',
                'match_score' => random_int(90, 99),
                'rating' => round(random_int(78, 96) / 10, 1),
                'is_original' => (bool) ($opts['is_original'] ?? false),
                'is_published' => (bool) ($opts['publish'] ?? true),
                'poster_path' => $st->poster_url,
                'backdrop_path' => $st->poster_url,
                'views' => $st->view_count,
            ],
        );

        $this->syncGenres($content, $this->resolveGenreOpts($st, $opts));
        $this->ensureUmbrella($content, $source);
        $count = $this->importEpisodes($source, $st, $content, $type);

        $st->update(['content_id' => $content->id, 'episodes_count' => $count]);

        return $content;
    }

    /**
     * Always keep every title in its source's umbrella genre (e.g. anime108 → "อนิเมะ"), on top of
     * whatever else was assigned — so the /anime page never misses an import, no checkbox required.
     */
    private function ensureUmbrella(Content $content, MediaSource $source): void
    {
        $name = $source->umbrellaGenre();
        if (! $name) {
            return;
        }
        $genre = Genre::firstOrCreate(
            ['name' => $name],
            ['slug' => Str::slug($name) ?: 'genre-'.Str::lower(Str::random(6)), 'sort' => 99],
        );
        $content->genres()->syncWithoutDetaching([$genre->id]);
    }

    /** Movie titles auto-split to type=movie when auto_type is on; otherwise the chosen/default type. */
    private function resolveType(MediaSource $source, SourceTitle $st, array $opts): string
    {
        if (($opts['auto_type'] ?? false) && ($st->extra['is_movie'] ?? false)) {
            return 'movie';
        }

        return $opts['type'] ?? $source->defaultContentType();
    }

    /**
     * With auto_genres on, derive genres from the source title's own categories (extra.genre_names,
     * matched to existing genres by name; primary = the "อนิเมะ" umbrella when present). Falls back
     * to the manually-chosen genres in $opts when there's no source metadata.
     */
    private function resolveGenreOpts(SourceTitle $st, array $opts): array
    {
        if (! ($opts['auto_genres'] ?? false)) {
            return $opts;
        }
        $names = $st->extra['genre_names'] ?? [];
        if (! is_array($names) || $names === []) {
            return $opts;
        }

        $idByName = Genre::whereIn('name', $names)->pluck('id', 'name');
        $ids = collect($names)->map(fn ($n) => $idByName[$n] ?? null)->filter()->values()->all();
        if ($ids === []) {
            return $opts;   // none of the mapped genres exist yet → keep manual selection
        }

        return ['genres' => $ids, 'primary_genre' => $idByName['อนิเมะ'] ?? $ids[0]] + $opts;
    }

    /** @return int number of episodes imported */
    private function importEpisodes(MediaSource $source, SourceTitle $st, Content $content, string $type): int
    {
        $remoteSeries = new RemoteSeries(
            source: $st->source,
            sourceKey: $st->source_key,
            title: $st->title,
            cleanTitle: $st->displayTitle(),
            extra: $st->extra ?? [],
        );

        $episodes = $source->fetchEpisodes($remoteSeries);

        // series get a single "all episodes" season so the detail modal renders them.
        $seasonId = null;
        if ($type === 'series' && $episodes !== []) {
            $seasonId = $content->seasons()->firstOrCreate(['number' => 1], ['title' => 'ตอนทั้งหมด'])->id;
        }

        foreach ($episodes as $ep) {
            $content->episodes()->updateOrCreate(
                ['number' => $ep['number'], 'season_id' => $seasonId],
                [
                    'source' => $st->source,
                    'source_ref' => $ep['ref'],
                    'title' => 'ตอนที่ '.$ep['number'],
                    'sort' => $ep['number'],
                    // video_url intentionally null — resolved on demand at watch time (URLs expire)
                ],
            );
        }

        return count($episodes);
    }

    private function syncGenres(Content $content, array $opts): void
    {
        if (! array_key_exists('genres', $opts)) {
            return; // leave genres untouched on re-import when none specified
        }
        $ids = $opts['genres'] ?? [];
        $primary = $opts['primary_genre'] ?? ($ids[0] ?? null);

        $content->genres()->sync(collect($ids)->mapWithKeys(fn ($id) => [
            $id => ['is_primary' => (int) $id === (int) $primary],
        ])->all());
    }

    private function uniqueSlug(string $title, SourceTitle $st): string
    {
        // Reuse the existing slug if this title was already imported.
        $existing = Content::where('source', $st->source)->where('source_key', $st->source_key)->value('slug');
        if ($existing) {
            return $existing;
        }

        $base = Str::slug($title);
        if ($base === '') {
            $base = $st->source.'-'.$st->source_key;
        }
        $slug = $base;
        $n = 2;
        while (Content::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
