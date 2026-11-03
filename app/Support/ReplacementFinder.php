<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentMirror;
use App\Models\Episode;
use App\Models\SourceTitle;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(private SourceRegistry $registry, private BackupFinder $verifier) {}

    /**
     * Candidate copies of $content across every synced site, best first.
     *
     * @return list<array{id:int,source:string,site:string,key:string,title:string,year:?int,poster:?string,episodes:?int,score:int,same_site:bool,mirrored:bool,preview:string}>
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

        $out = [];
        foreach ($rows as $st) {
            $score = self::score($want, Content::dedupeKey($st->displayTitle()), $content->year, $st->year);
            if ($score < self::MIN_SCORE) {
                continue;
            }
            $source = $this->registry->get($st->source);
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
                'preview' => route('admin.preview.source', $st),
            ];
        }

        usort($out, fn ($a, $b) => [$b['score'], $b['episodes'] ?? 0] <=> [$a['score'], $a['episodes'] ?? 0]);

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
            return ['ok' => false, 'kind' => null, 'message' => 'ขอลิงก์ตอนที่ '.$ref.' จาก '.$source->displayName().' ไม่ได้'];
        }
        if ($stream->kind === RemoteStream::KIND_HLS && ! $this->verifier->plays($stream)) {
            return ['ok' => false, 'kind' => $stream->kind, 'message' => 'ได้ลิงก์มาแต่วิดีโอเล่นไม่ได้ (playlist หรือไฟล์วิดีโอเสีย)'];
        }

        return ['ok' => true, 'kind' => $stream->kind, 'message' => 'ตอนที่ '.$ref.' เล่นได้'];
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
            Episode::where('content_id', $content->id)->update(['active_mirror_id' => $mirror->id]);

            return $mirror;
        });

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
     * The bot's version: try the confident matches only (same title, compatible year), attach the first
     * that verifies. A wrong guess would republish the wrong film, so anything weaker is left for the
     * admin to judge in the suspended list.
     *
     * @return array{source:string,key:string,site:string}|null
     */
    public function rescue(Content $content, int $tries = 4): ?array
    {
        $strong = array_filter($this->candidates($content, 10), fn ($c) => $c['score'] >= self::STRONG);
        foreach (array_slice(array_values($strong), 0, $tries) as $c) {
            if ($this->verify($content, $c['source'], $c['key'])['ok']) {
                $this->attach($content, $c['source'], $c['key']);

                return ['source' => $c['source'], 'key' => $c['key'], 'site' => $c['site']];
            }
        }

        return null;
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
