<?php

namespace App\Services\Import;

use App\Models\Content;
use App\Models\Genre;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Contracts\ProvidesSynopsis;
use App\Support\VerticalGenre;
use Illuminate\Support\Str;

class ImportService
{
    public function __construct(private SourceRegistry $registry) {}

    /**
     * Sync a source's remote catalogue into `source_titles` (upsert). Returns the number synced.
     * Persists per page/batch so a timeout still keeps earlier pages.
     *
     * $onProgress (optional) is invoked after each persisted batch with the running synced count — used
     * by [App\Jobs\SyncCatalogJob] to drive the live progress poll AND to abort mid-run (it throws
     * [App\Jobs\SyncStopped] when the admin presses stop; fetchCatalog lets that propagate out).
     */
    public function sync(string $sourceId, int $maxPages = 100, ?callable $onProgress = null): int
    {
        $source = $this->registry->get($sourceId);
        if (! $source) {
            return 0;
        }

        $running = 0;

        return $source->fetchCatalog(function (array $batch) use ($sourceId, $onProgress, &$running) {
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
                $running++;
            }
            if ($onProgress) {
                $onProgress($running);
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
        [$maturity, $isVip] = $this->resolveMaturity($st);

        $content = Content::updateOrCreate(
            ['source' => $st->source, 'source_key' => $st->source_key],
            [
                'title' => $title,
                'slug' => $this->uniqueSlug($title, $st),
                'type' => $type,
                'synopsis' => $st->description,
                'year' => $st->year,
                'maturity' => $maturity,
                'is_vip' => $isVip,
                'dub_type' => $st->dub_type ?: Content::guessDubType($title),
                'match_score' => random_int(90, 99),
                'rating' => round(random_int(78, 96) / 10, 1),
                'is_original' => (bool) ($opts['is_original'] ?? false),
                'is_published' => (bool) ($opts['publish'] ?? true),
                'poster_path' => $st->poster_url,
                'backdrop_path' => $st->poster_url,
                'views' => $st->view_count ?? 0,
            ],
        );

        $this->syncGenres($content, $this->resolveGenreOpts($st, $opts));
        $this->ensureUmbrella($content, $source);
        $count = $this->importEpisodes($source, $st, $content, $type);
        $this->fillSynopsis($source, $st, $content);
        $this->ensureGuessedGenre($content);   // after fillSynopsis so the guess can use the synopsis

        $st->update(['content_id' => $content->id, 'episodes_count' => $count]);

        return $content;
    }

    /**
     * Some sources hide the plot synopsis behind a detail page (not in the catalogue feed). When the
     * title still has no synopsis, scrape it once and store it on both the content and the source
     * title (so a re-import doesn't scrape again). Best-effort — never fail an import over it.
     */
    private function fillSynopsis(MediaSource $source, SourceTitle $st, Content $content): void
    {
        if (! $source instanceof ProvidesSynopsis || filled($content->synopsis)) {
            return;
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
        } catch (\Throwable $e) {
            return;
        }
        if (filled($syn)) {
            $content->forceFill(['synopsis' => $syn])->save();
            if (blank($st->description)) {
                $st->forceFill(['description' => $syn])->save();
            }
        }
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

    /**
     * Last-resort genre for a title that STILL has none after source metadata + umbrella. Several
     * sources expose no usable content genre: rongyok (none anywhere), wowdrama (WP categories are only
     * country — จีน/เกาหลี/ไทย/ญี่ปุ่น), 9nung (mostly country-classified). Rather than leave them
     * genre-less, guess a genre from the Thai title + synopsis by keyword ([App\Support\VerticalGenre],
     * the same guesser verticals use). Best-effort, never overrides a real/umbrella genre, and cheap —
     * no network, just text we already have. Runs after fillSynopsis so the synopsis informs the guess.
     */
    private function ensureGuessedGenre(Content $content): void
    {
        if ($content->genres()->exists()) {
            return;
        }
        if ($gid = VerticalGenre::guessId(trim($content->title.' '.(string) $content->synopsis))) {
            $content->genres()->syncWithoutDetaching([$gid => ['is_primary' => true]]);
        }
    }

    /**
     * Maturity + VIP for an imported title. A source can flag a title adult via `extra.adult` (9.9nung's
     * "erotic" R18+ genre — see [App\Services\Import\Sources\NaayNungSource]); such titles import as
     * 18+ (kids-hidden + Pro-gated) AND is_vip (VIP-premium zone) — owner rule 2026-07-06. Non-adult
     * titles keep their existing rating/VIP on re-import (don't clobber an admin's manual bump), else
     * default 15+.
     *
     * @return array{0:string,1:bool}  [maturity, is_vip]
     */
    private function resolveMaturity(SourceTitle $st): array
    {
        if (is_array($st->extra) && ! empty($st->extra['adult'])) {
            return ['18+', true];
        }

        $existing = Content::where('source', $st->source)->where('source_key', $st->source_key)
            ->first(['maturity', 'is_vip']);

        return [$existing?->maturity ?: '15+', (bool) ($existing?->is_vip ?? false)];
    }

    /**
     * With auto_type on, split by the source's own is_movie flag BOTH ways — movies → movie,
     * everything else → series (so a movie source like 24-hdx files its ซีรีส์ as type=series with
     * real episodes, and anime108 movies still become type=movie). Sources that don't carry the flag
     * (rongyok=vertical, wowdrama) are untouched and keep their default type.
     */
    private function resolveType(MediaSource $source, SourceTitle $st, array $opts): string
    {
        if (($opts['auto_type'] ?? false) && is_array($st->extra) && array_key_exists('is_movie', $st->extra)) {
            return $st->extra['is_movie'] ? 'movie' : 'series';
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

        // Drop leftovers from a previous import of the OTHER shape (a movie's episode has
        // season_id=null; a series' episodes hang off a season) so re-importing a title whose type
        // changed — e.g. a movie that gained episodes and became a series — doesn't leave an orphan
        // ตอนที่ 1. Only runs once we've actually upserted the real episodes.
        if ($episodes !== []) {
            $content->episodes()
                ->when($type === 'series', fn ($q) => $q->whereNull('season_id'))
                ->when($type !== 'series', fn ($q) => $q->whereNotNull('season_id'))
                ->delete();
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
