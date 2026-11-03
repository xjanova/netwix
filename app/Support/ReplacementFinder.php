<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentMirror;
use App\Models\Episode;
use App\Models\SourceTitle;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the same title on another site (or a fresh upload on its own site) for a title that can no
 * longer play, and wires the one the admin — or the find-backups bot — picks in as a mirror.
 *
 * [BackupFinder] only searches the Halim pool live. This searches every site's LOCALLY SYNCED
 * catalogue (`source_titles`, refreshed by the importers), which covers every source including the
 * anime ones, costs one query, and finds a title even when the site's own search is broken. Owner
 * (2026-11-03): a dead title should try every other link we can find before it stays dead.
 *
 * A pick becomes a manual [ContentMirror] that every episode plays first (active_mirror_id), so
 * [MirrorRotation] walks it before the dead primary and still falls back to the rest of the chain.
 */
class ReplacementFinder
{
    /** Scores at or above this are the same title under another site's naming; below it, a guess. */
    public const STRONG = 90;

    private const MIN_SCORE = 55;

    /** How long a copy that failed its play check is skipped by the bot (and flagged to the admin). */
    private const FAILED_TTL_DAYS = 3;

    public function __construct(private SourceRegistry $registry, private BackupFinder $verifier) {}

    /**
     * Candidate copies of $content across every synced site, best first.
     *
     * `covers` is false when the site's copy has fewer episodes than we list (null = unknown / a
     * movie); `failed` marks a copy whose play check failed lately; `imported_as` is our own entry
     * for that very copy, when a re-upload was imported as a title of its own.
     *
     * @return list<array{id:int,source:string,site:string,key:string,title:string,year:?int,poster:?string,episodes:?int,score:int,same_site:bool,mirrored:bool,covers:?bool,failed:bool,imported_as:?array{id:int,title:string,published:bool,episodes:int},preview:string}>
     */
    public function candidates(Content $content, int $limit = 15): array
    {
        $want = Content::dedupeKey($content->title);
        if ($want === '') {
            return [];
        }
        $terms = self::searchTerms($content->title);
        if ($terms === []) {
            return [];
        }

        $mirrored = ContentMirror::where('content_id', $content->id)->pluck('source_key', 'source');
        $rows = SourceTitle::query()
            ->whereIn('source', array_keys($this->registry->all()))
            // Its own row on its own site is the link that just died — never a replacement for itself.
            ->where(fn ($w) => $w->where('source', '!=', (string) $content->source)->orWhere('source_key', '!=', (string) $content->source_key))
            ->where(function ($w) use ($terms) {
                foreach ($terms as $t) {
                    $w->orWhere('clean_title', 'like', '%'.$t.'%')->orWhere('title', 'like', '%'.$t.'%');
                }
            })
            ->orderByDesc('view_count')
            ->limit(300)
            ->get();

        $rows = $rows->filter(fn (SourceTitle $st) => self::score($want, Content::dedupeKey($st->displayTitle()), $content->year, $st->year) >= self::MIN_SCORE);
        $span = self::episodeSpan($content);
        $imported = $this->importedAs($content, $rows);

        $out = [];
        foreach ($rows as $st) {
            $score = self::score($want, Content::dedupeKey($st->displayTitle()), $content->year, $st->year);
            $source = $this->registry->get($st->source);
            $own = $imported[$st->source."\0".$st->source_key] ?? null;
            $out[] = [
                'id' => $st->id,
                'source' => $st->source,
                'site' => $source?->displayName() ?? $st->source,
                'key' => $st->source_key,
                'title' => $st->displayTitle(),
                'year' => $st->year ? (int) $st->year : null,
                'poster' => MediaUrl::adminPoster($st->poster_url),
                'episodes' => $st->episodes_count ? (int) $st->episodes_count : null,
                'score' => $score,
                'same_site' => $st->source === $content->source,
                'mirrored' => ($mirrored[$st->source] ?? null) === $st->source_key,
                'covers' => $span === null || ! $st->episodes_count ? null : (int) $st->episodes_count >= $span,
                'failed' => self::failedRecently($content, $st->source, $st->source_key),
                'imported_as' => $own ? [
                    'id' => $own->id,
                    'title' => (string) $own->title,
                    'published' => (bool) $own->is_published,
                    'episodes' => (int) $own->episodes_count,
                ] : null,
                'preview' => route('admin.preview.source', $st),
            ];
        }

        // Best title first; among equals, a copy that hasn't just failed and that has every episode.
        $rank = fn (array $c) => [$c['score'], $c['failed'] ? 0 : 1, $c['covers'] === false ? 0 : 1, $c['episodes'] ?? 0];
        usort($out, fn ($a, $b) => $rank($b) <=> $rank($a));

        return array_slice($out, 0, $limit);
    }

    /**
     * Does $source/$key play $content's first episode, the way [MirrorRotation] would ask for it?
     *
     * @return array{ok:bool,kind:?string,message:string}
     */
    public function verify(Content $content, string $sourceId, string $key): array
    {
        $source = $this->registry->get($sourceId);
        if (! $source) {
            return ['ok' => false, 'kind' => null, 'message' => 'ไม่พบเว็บนี้ในระบบ'];
        }
        $first = $content->episodes()->orderBy('number')->first();
        $ref = $content->type === 'movie' || ! $first ? '1' : (string) $first->number;

        try {
            $stream = $source->resolveByRef($key, $ref);
        } catch (\Throwable) {
            $stream = null;
        }
        if ($stream === null) {
            return $this->failed($content, $sourceId, $key, null, 'ขอลิงก์ตอนที่ '.$ref.' จาก '.$source->displayName().' ไม่ได้');
        }
        if ($stream->kind === RemoteStream::KIND_HLS && ! $this->verifier->plays($stream)) {
            return $this->failed($content, $sourceId, $key, $stream->kind, 'ได้ลิงก์มาแต่วิดีโอเล่นไม่ได้ (playlist หรือไฟล์วิดีโอเสีย)');
        }

        // A copy that plays episode 1 can still stop half-way: ask for our last episode too. Only a
        // clean "no such episode" fails it — a throw is the site being slow, not proof.
        $span = self::episodeSpan($content);
        if ($span !== null && $span > (int) $ref) {
            try {
                $last = $source->resolveByRef($key, (string) $span);
            } catch (\Throwable) {
                $last = false;
            }
            if ($last === null) {
                return $this->failed($content, $sourceId, $key, $stream->kind,
                    'ตอนที่ '.$ref.' เล่นได้ แต่ขอตอนที่ '.$span.' ไม่ได้ (เว็บนี้อาจมีไม่ครบทุกตอน)');
            }
        }

        Cache::forget(self::failedKey($content, $sourceId, $key));

        return ['ok' => true, 'kind' => $stream->kind, 'message' => 'ตอนที่ '.$ref.' เล่นได้'];
    }

    /**
     * A verify that failed: remembered for FAILED_TTL_DAYS, so the daily bot stops re-trying the same
     * dead copy and the admin list shows it was tried.
     *
     * @return array{ok:false,kind:?string,message:string}
     */
    private function failed(Content $content, string $sourceId, string $key, ?string $kind, string $message): array
    {
        Cache::put(self::failedKey($content, $sourceId, $key), time(), now()->addDays(self::FAILED_TTL_DAYS));

        return ['ok' => false, 'kind' => $kind, 'message' => $message];
    }

    /**
     * Make $source/$key the link every episode of $content tries first, and put the title back on the
     * site. Idempotent: picking the same copy twice keeps one mirror row.
     */
    public function attach(Content $content, string $sourceId, string $key): ContentMirror
    {
        $mirror = DB::transaction(function () use ($content, $sourceId, $key) {
            $mirror = ContentMirror::updateOrCreate(
                ['content_id' => $content->id, 'source' => $sourceId],
                ['source_key' => $key, 'is_manual' => true],
            );
            $mirror->forceFill(['fail_streak' => 0])->save();

            // Episodes past the end of the site's copy keep starting at their own link — pointing them
            // at a mirror that doesn't have them would only cost a failed hop (and a fail_streak) each.
            $episodes = Episode::where('content_id', $content->id);
            $count = (int) SourceTitle::where('source', $sourceId)->where('source_key', $key)->value('episodes_count');
            if ($content->type !== 'movie' && $count > 0) {
                $episodes->where('number', '<=', $count);
            }
            $episodes->update(['active_mirror_id' => $mirror->id]);

            return $mirror;
        });
        Cache::forget(self::failedKey($content, $sourceId, $key));

        foreach (Episode::where('content_id', $content->id)->pluck('id') as $id) {
            Cache::forget("ep_raw:{$id}");
            Cache::forget("ep_raw:{$id}:miss");
            Cache::forget("ep_manifest:{$id}");
        }
        if ($content->suspended_at || $content->is_published) {
            PlaybackHealth::republish($content);
        }

        return $mirror;
    }

    /**
     * The bot's version: confident matches only (same title, compatible year). A wrong guess would
     * republish the wrong film, so anything weaker is left for the admin to judge in the suspended list.
     *
     *  - When that copy is ALREADY live on our site as its own entry (a re-upload the importer took for
     *    a new title), publishing this one too would only list the title twice: it is retired instead,
     *    and its watch/title URLs send viewers to the live entry ([self::retire]).
     *  - Otherwise the first copy that verifies is attached — skipping copies that failed lately and
     *    copies the site lists with fewer episodes than ours.
     *
     * @return array{kind:string,source:string,key:string,site:string,duplicate_of?:int}|null
     */
    public function rescue(Content $content, int $tries = 4): ?array
    {
        $strong = array_values(array_filter($this->candidates($content, 10), fn ($c) => $c['score'] >= self::STRONG));

        foreach ($strong as $c) {
            $own = $c['imported_as'];
            if ($own && $own['published'] && $own['episodes'] > 0) {
                $live = Content::withoutGlobalScopes()->find($own['id']);
                if ($live) {
                    $this->retire($content, $live);

                    return ['kind' => 'duplicate', 'source' => $c['source'], 'key' => $c['key'], 'site' => $c['site'], 'duplicate_of' => $live->id];
                }
            }
        }

        if (! $content->episodes()->exists()) {
            return null;   // nothing for a link to play — an empty entry must not come back on the site
        }

        $tried = 0;
        foreach ($strong as $c) {
            if ($c['failed'] || $c['covers'] === false) {
                continue;
            }
            if (++$tried > $tries) {
                break;
            }
            if ($this->verify($content, $c['source'], $c['key'])['ok']) {
                $this->attach($content, $c['source'], $c['key']);

                return ['kind' => 'mirror', 'source' => $c['source'], 'key' => $c['key'], 'site' => $c['site']];
            }
        }

        return null;
    }

    /**
     * Take $dead off the site for good in favour of $live, the same title already published under
     * another entry. Nothing is deleted (its comments, likes and history stay); its pages redirect to
     * $live ([Content::duplicateTarget]) and it leaves the suspended list.
     */
    public function retire(Content $dead, Content $live): void
    {
        $dead->forceFill([
            'is_published' => false,
            'suspended_at' => null,
            'suspend_reason' => 'duplicate:'.$live->id,
            'review_flagged_at' => null,
        ])->save();
        Cache::forget('admin:suspended_count');
    }

    /**
     * How alike two titles are, 0-100. Equal normalised keys are the same title; one containing the
     * other is usually a site adding "ภาค 2"/"The Series"; past that, character similarity. A year that
     * disagrees is how remakes and same-named sequels give themselves away.
     */
    public static function score(string $want, string $have, ?int $wantYear, ?int $haveYear): int
    {
        if ($have === '') {
            return 0;
        }
        if ($want === $have) {
            $score = 100;
        } elseif (mb_strlen($have) >= 4 && (str_contains($want, $have) || str_contains($have, $want))) {
            $score = 80;
        } else {
            similar_text($want, $have, $pct);
            $score = (int) round($pct * 0.75);
        }
        if ($wantYear && $haveYear && (int) $wantYear !== (int) $haveYear) {
            $score -= 25;
        }

        return max(0, $score);
    }

    /** Our highest episode number for a series, or null for a movie / an entry with none. */
    private static function episodeSpan(Content $content): ?int
    {
        if ($content->type === 'movie') {
            return null;
        }
        $max = $content->episodes()->max('number');

        return $max ? (int) $max : null;
    }

    /**
     * Our own entries for these catalogue rows, keyed "source\0key" — how a re-upload that the importer
     * took for a new title is recognised.
     *
     * @param  Collection<int,SourceTitle>  $rows
     * @return array<string,Content>
     */
    private function importedAs(Content $content, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        return Content::withoutGlobalScopes()
            ->whereIn('source', $rows->pluck('source')->unique()->all())
            ->whereIn('source_key', $rows->pluck('source_key')->unique()->all())
            ->where('id', '!=', $content->id)
            ->where('type', $content->type)
            ->withCount('episodes')
            ->get(['id', 'title', 'type', 'source', 'source_key', 'is_published'])
            ->keyBy(fn (Content $c) => $c->source."\0".$c->source_key)
            ->all();
    }

    private static function failedRecently(Content $content, string $sourceId, string $key): bool
    {
        return Cache::has(self::failedKey($content, $sourceId, $key));
    }

    private static function failedKey(Content $content, string $sourceId, string $key): string
    {
        return "replace:failed:{$content->id}:".sha1($sourceId."\0".$key);
    }

    /**
     * LIKE terms that find the title under another site's naming: the whole title stripped of dub
     * tags, plus its longest words (Thai and English halves of a bilingual title are usually split by
     * a space, and either half alone finds the other site's copy).
     *
     * @return list<string>
     */
    public static function searchTerms(string $title): array
    {
        $clean = trim((string) preg_replace('~\s+~u', ' ', (string) preg_replace('~พากย์ไทย|ซับไทย|เต็มเรื่อง|ตอนจบ|ครบทุกตอน|[\[\]\(\)|:,!?"\']~u', ' ', $title)));
        $words = array_values(array_filter(explode(' ', $clean), fn ($w) => mb_strlen($w) >= 3 && ! ctype_digit($w)));
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique(array_filter([mb_substr($clean, 0, 60), ...array_slice($words, 0, 3)], fn ($t) => mb_strlen($t) >= 3)));
    }
}
