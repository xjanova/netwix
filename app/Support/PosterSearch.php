<?php

namespace App\Support;

use App\Models\Content;
use App\Services\Import\Contracts\SearchesPosters;
use App\Services\Import\SourceRegistry;

/**
 * Finds a cover for a title BY NAME, across every source that can be searched.
 *
 * The reason this exists rather than just re-scraping the origin: what is actually missing covers on
 * NetWix is not fixable at the origin. Measured on prod 2026-08-19 — 46 titles hold no poster URL at
 * all (37 of them 24-hdx), and of the 226 still hotlinking, 211 belong to anifume and anime108, two
 * sources that have gone dark. Re-opening the title's own page ([App\Support\PosterBackfill] via
 * [App\Services\Import\Contracts\ProvidesPoster]) can never answer for those. A live site that
 * carries the same film can.
 *
 * So the lookup is deliberately cross-source, and deliberately advisory: matching on a NAME can be
 * wrong (sequels, remakes, a shared Thai subtitle), and a wrong cover is worse than the branded
 * fallback. Every result is scored and shown to the admin to confirm — only an exact normalised-title
 * hit is safe enough to apply without someone looking, and even that is opt-in per run.
 *
 * @see \App\Http\Controllers\Admin\CoverController
 */
class PosterSearch
{
    /** Below this the titles simply aren't the same film — don't waste a row on it. */
    public const MIN_SCORE = 0.45;

    /** At or above this the normalised titles agree well enough to apply without a human looking. */
    public const AUTO_SCORE = 0.92;

    public function __construct(private SourceRegistry $registry) {}

    /**
     * Poster candidates for a title, best match first.
     *
     * The title's own source is asked first — it holds the same catalogue our row was imported from,
     * so it is the likeliest to have the very same film — and the others are there for when that
     * source is gone. Once one site answers with a confident match the rest are skipped, because each
     * one costs a live HTTP round-trip and the admin is waiting on it.
     *
     * @return PosterCandidate[]
     */
    public function find(Content $content, int $limit = 8): array
    {
        $title = (string) $content->title;
        if (trim($title) === '') {
            return [];
        }

        $out = [];
        foreach ($this->searchableFor($content) as $id => $source) {
            try {
                $found = $source->searchPosters($this->queryFor($title), $limit);
            } catch (\Throwable) {
                continue;   // one dead site must never sink the whole lookup
            }
            foreach ($found as $c) {
                $c->source = $id;
                $out[] = $c;
            }
            if ($this->best($this->rank($title, $out)) >= self::AUTO_SCORE) {
                break;
            }
        }

        return array_slice($this->rank($title, $out), 0, $limit);
    }

    /**
     * Score candidates against our title and drop the ones that clearly aren't it.
     *
     * @param  PosterCandidate[]  $candidates
     * @return PosterCandidate[]
     */
    public function rank(string $title, array $candidates): array
    {
        $ours = self::titleKey($title);
        $kept = [];
        foreach ($candidates as $c) {
            $c->score = self::similarity($ours, self::titleKey($c->title));
            if ($c->score >= self::MIN_SCORE) {
                $kept[] = $c;
            }
        }
        usort($kept, fn (PosterCandidate $a, PosterCandidate $b) => $b->score <=> $a->score);

        return $kept;
    }

    /**
     * Normalised comparison key for a title from ANY site.
     *
     * [Content::dedupeKey] already folds away dub/quality tags, but it was written for titles as our
     * own catalogue stores them. A title read off a search-results page carries the site's listing
     * chrome as well — "ดูซีรี่ย์ Our Sticky Love (2026) รักติดหนึบ ซับไทย EP.1-12 จบ" — and every one
     * of those words is noise against our "Our Sticky Love (2026) รักติดหนึบ". Strip the chrome first
     * so the score reflects the film, not the theme. dedupeKey itself is left alone: it is stored per
     * row and matched by [App\Support\MirrorLinker], so its meaning must not shift.
     */
    public static function titleKey(string $title): string
    {
        $t = preg_replace('~^\s*ดู(?:หนัง|ซีรี่ย์|ซีรี่ส์|ซีรีส์|อนิเมะ|การ์ตูน)\s*~u', ' ', $title) ?? $title;
        $t = preg_replace('~\bEP\.?\s*\d+(?:\s*[-–]\s*\d+)?~iu', ' ', $t) ?? $t;
        // Only a PARENTHESISED year. Stripping any bare 4-digit run would erase the entire title of
        // "2012" or "1917", and both sides spell the year the same way anyway, so keeping it is free.
        $t = preg_replace('~\(\s*\d{4}\s*\)~u', ' ', $t) ?? $t;

        return Content::dedupeKey($t);
    }

    /**
     * 0..1 agreement between two already-normalised title keys.
     *
     * `similar_text` alone punishes the very common case where a source spells the film out longer
     * than we do ("Fairy Tail แฟรี่เทล ศึกจอมเวท" vs "Fairy Tail 100-nen Quest แฟรี่เทล ศึกจอมเวท
     * ภารกิจ 100 ปี ซับไทย"): the extra words drag the percentage down even though ours is contained
     * whole. Containment is scored separately and the better of the two wins, held just under 1.0 so
     * a genuine exact match always outranks a title that merely contains ours.
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $pct = 0.0;
        // similar_text is O(n^3) worst case — bound the input so a pathological title can't stall a
        // request. 120 chars is well past where two titles have already decided the question.
        similar_text(mb_substr($a, 0, 120, 'UTF-8'), mb_substr($b, 0, 120, 'UTF-8'), $pct);
        $score = $pct / 100;

        $short = mb_strlen($a, 'UTF-8') <= mb_strlen($b, 'UTF-8') ? $a : $b;
        $long = $short === $a ? $b : $a;
        if (mb_strlen($short, 'UTF-8') >= 8 && str_contains($long, $short)) {
            $score = max($score, 0.9);
        }

        return round($score, 3);
    }

    /**
     * The string to type into the source's search box.
     *
     * Our titles are the whole import line — "Akhanda 2 (2026) อภินิหารทัณฑ์เทพล้างอธรรม" — and site
     * search engines match it worse the longer it gets. The leading Latin name is what these sites
     * index (verified against 24-hdx's autocomplete on 2026-08-19), so lead with that, falling back
     * to the Thai text for the titles that have no Latin name at all.
     */
    private function queryFor(string $title): string
    {
        $t = preg_replace('~\(\s*\d{4}\s*\)~u', ' ', $title) ?? $title;   // drop the year
        $t = trim(preg_replace('~\s+~u', ' ', $t) ?? $t);

        if (preg_match('~^[\x20-\x7E]{4,}~', $t, $m)) {
            $latin = trim($m[0]);
            if (mb_strlen($latin, 'UTF-8') >= 4) {
                return mb_substr($latin, 0, 60, 'UTF-8');
            }
        }

        return mb_substr($t, 0, 60, 'UTF-8');
    }

    /**
     * Sources worth asking for this title, own-source first.
     *
     * @return array<string,SearchesPosters>
     */
    private function searchableFor(Content $content): array
    {
        $all = [];
        foreach ($this->registry->all() as $id => $source) {
            if ($source instanceof SearchesPosters) {
                $all[$id] = $source;
            }
        }

        $own = (string) $content->source;
        if ($own !== '' && isset($all[$own])) {
            $all = [$own => $all[$own]] + $all;
        }

        return $all;
    }

    /** @param  PosterCandidate[]  $ranked */
    private function best(array $ranked): float
    {
        return $ranked === [] ? 0.0 : $ranked[0]->score;
    }
}
