<?php

namespace App\Services\Import;

use App\Models\SourceTitle;
use Illuminate\Database\Eloquent\Builder;

/**
 * Selects + refreshes imported titles that can legitimately gain episodes at the source (airing
 * dramas/anime release weekly). One shared brain for the three entry points: the
 * `netwix:refresh-episodes` command (nightly + manual sweeps), the per-title "รีเฟรชตอน" admin
 * button ([Admin\ImportController::refreshEpisodes]), and the post-sync refresh
 * ([App\Jobs\RefreshEpisodesJob]) — so their selection rules can never drift apart.
 *
 * Which titles are refreshable:
 *   - wowdrama / anifume       → all (long-form series / airing anime — every title can gain episodes)
 *   - 24hdx / anime108 / 9nung → only where the source flags is_movie=false (also catches the
 *                                1-episode "movie" that is really a series, e.g. The Evil Lawyer;
 *                                9nung series = the clean-fembed /tvshows/ subset)
 *   - rongyok                  → opt-in only (short verticals arrive complete; low value)
 *   - movies                   → never (single videos can't gain episodes)
 *
 * See [[NetWix — airing series stuck at old episode count (auto-import never re-imports)]].
 */
class EpisodeRefresher
{
    /** Raw-title markers that a series has finished — airing-only skips completed titles. */
    public const ENDED_MARKERS = ['จบ', 'ครบทุกตอน', 'END'];

    public function __construct(private ImportService $importer) {}

    /** Builder over refreshable source titles (imported + multi-episode-capable). */
    public function query(?string $source = null, bool $includeRongyok = false): Builder
    {
        return SourceTitle::query()->imported()
            ->when($source, fn ($w) => $w->where('source', $source))
            ->where(function ($w) use ($includeRongyok) {
                // wowdrama + anifume: every title is a series that can gain episodes.
                $w->whereIn('source', ['wowdrama', 'anifume'])
                    // Halim + 9nung: only titles the source itself flags as a series (9nung's
                    // abyss movies are excluded here by is_movie=true / missing key).
                    ->orWhere(fn ($x) => $x->whereIn('source', ['24hdx', 'anime108', '9nung'])
                        ->whereRaw("JSON_EXTRACT(extra, '$.is_movie') = false"));
                if ($includeRongyok) {
                    $w->orWhere('source', 'rongyok');
                }
            });
    }

    /**
     * Narrow to titles that look still-airing: recently imported AND no "ended" marker in the raw
     * title. Least-recently-touched first, so a bounded run rotates through the pool over time
     * (refresh() touches the row even when nothing changed — see below).
     */
    public function airingOnly(Builder $q, int $recentDays = 60): Builder
    {
        $q->whereHas('content', fn ($w) => $w->where('created_at', '>=', now()->subDays(max(1, $recentDays))));
        foreach (self::ENDED_MARKERS as $m) {
            $q->where('title', 'not like', '%'.$m.'%');
        }

        return $q->orderBy('updated_at');
    }

    /**
     * Re-import one title in place: upserts the current episode list, PRESERVES publish state +
     * genres + maturity, and fixes movie↔series mis-typing via auto_type (the source's own
     * is_movie flag).
     *
     * @return array{title:string,before:int,after:int,type:string,retyped:bool}
     */
    public function refresh(SourceTitle $st): array
    {
        $content = $st->content;
        $before = $content?->episodes()->count() ?? 0;
        $typeBefore = $content?->type;

        $fresh = $this->importer->import($st, [
            'auto_type' => true,
            'publish' => (bool) ($content?->is_published ?? true),
        ]);

        // Always advance updated_at — import() only saves when episodes_count changed, so without
        // this a never-changing title would sit at the front of the least-recently-touched rotation
        // forever and starve the rest of the pool.
        $st->touch();

        // import() returned null → the title's player is now an un-playable embed (abyss). Leave the
        // existing content untouched (the playability recheck will hide it); just report a no-op.
        if ($fresh === null) {
            return ['title' => $st->displayTitle(), 'before' => $before, 'after' => $before, 'type' => $typeBefore, 'retyped' => false, 'skipped' => true];
        }

        // HARD publish-state guarantee: import() force-unpublishes hidden_sources titles (e.g. 9nung)
        // regardless of opts — but for a REFRESH the publish state is owned elsewhere (the admin's
        // choice / netwix:recheck-playable's per-title playability verdict), so restore whatever it
        // was before. Refresh must only ever touch episodes/typing, never visibility.
        if ($content && (bool) $fresh->is_published !== (bool) $content->is_published) {
            $fresh->forceFill(['is_published' => (bool) $content->is_published])->save();
        }

        return [
            'title' => $st->displayTitle(),
            'before' => $before,
            'after' => $fresh->episodes()->count(),
            'type' => $fresh->type,
            'retyped' => $typeBefore !== null && $fresh->type !== $typeBefore,
        ];
    }
}
