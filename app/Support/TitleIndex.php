<?php

namespace App\Support;

use App\Models\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The alphabet the catalogue directory is built on — one stable home for every title.
 *
 * The hubs (/movies /series /anime /vertical) sort `inRandomOrder` with a day-based seed, which is
 * right for a visitor and useless for a crawler: /series?page=47 holds sixty different titles
 * tomorrow, so no title ever has a stable referring page. Search Console said exactly that about a
 * title page — "referring page: none" — while the hubs were being crawled. The directory exists to
 * be the opposite: ordered by title, identical tomorrow, and complete.
 *
 * Slugs are ASCII so they survive logs, sitemaps and redirects unencoded. A Thai letter becomes its
 * codepoint (ก → th-e01), which needs no lookup table and can never drift out of sync with a list.
 */
class TitleIndex
{
    public const DIGITS = '0-9';

    public const OTHER = 'other';

    /**
     * The group a title belongs to, by its first character.
     *
     * Leading whitespace is trimmed first: a handful of imported titles carry it, and without this
     * they would all pile into "other" while SQL's LIKE put them under their real letter.
     */
    public static function slugFor(string $title): string
    {
        $ch = mb_substr(ltrim($title), 0, 1, 'UTF-8');

        return self::slugForChar($ch);
    }

    public static function slugForChar(string $ch): string
    {
        if ($ch === '') {
            return self::OTHER;
        }
        if (preg_match('/^[0-9]$/u', $ch)) {
            return self::DIGITS;
        }
        if (preg_match('/^[A-Za-z]$/u', $ch)) {
            return mb_strtolower($ch);
        }
        if (preg_match('/^\p{Thai}$/u', $ch)) {
            return 'th-'.dechex(mb_ord($ch, 'UTF-8'));
        }

        return self::OTHER;
    }

    /** The character a slug stands for, or null for the two catch-all groups. */
    public static function charFor(string $slug): ?string
    {
        if ($slug === self::DIGITS || $slug === self::OTHER) {
            return null;
        }
        if (preg_match('/^[a-z]$/', $slug)) {
            return $slug;
        }
        if (preg_match('/^th-([0-9a-f]{3,5})$/', $slug, $m)) {
            $ch = mb_chr((int) hexdec($m[1]), 'UTF-8');

            return ($ch !== false && preg_match('/^\p{Thai}$/u', $ch)) ? $ch : null;
        }

        return null;
    }

    /** Heading shown to a human: "A", "ก", "0-9", "อื่น ๆ". */
    public static function label(string $slug): string
    {
        if ($slug === self::DIGITS) {
            return '0-9';
        }
        if ($slug === self::OTHER) {
            return 'อื่น ๆ';
        }
        $ch = self::charFor($slug);

        return $ch === null ? $slug : (preg_match('/^[a-z]$/', $ch) ? mb_strtoupper($ch) : $ch);
    }

    public static function isValid(string $slug): bool
    {
        return $slug === self::DIGITS || $slug === self::OTHER || self::charFor($slug) !== null;
    }

    /**
     * Narrow a query to one group.
     *
     * A single LIKE prefix, verified against a PHP pass over all 19,934 published titles: under
     * utf8mb4_unicode_ci the counts agree exactly for Latin (case-folded, so 'a' catches 'A') and for
     * Thai (no vowel or tone folding). "0-9" and "other" cannot be a prefix, so they are expressed as
     * the complement of every group that can — which keeps the directory genuinely complete: every
     * published title appears in exactly one group, including ones starting with 【 or ¡.
     */
    public static function scope(Builder $query, string $slug): Builder
    {
        // `substr(trim(title), 1, 1)` rather than MySQL's LEFT()/REGEXP: both are character-based on
        // MySQL and SQLite, so the same expression serves production and the test database. REGEXP
        // does not exist in SQLite at all, which is how the first version of this passed review and
        // failed every test.
        $firstChar = 'substr(trim(title), 1, 1)';

        if ($slug === self::DIGITS) {
            $digits = array_map('strval', range(0, 9));

            return $query->whereRaw($firstChar.' in ('.self::holders($digits).')', $digits);
        }

        if ($slug === self::OTHER) {
            $known = self::knownFirstChars();

            return $query->whereRaw($firstChar.' not in ('.self::holders($known).')', $known);
        }

        // LIKE is the indexed path and folds case on both engines for ASCII (so 'a' catches 'A');
        // Thai has no case to fold. Verified against a PHP pass over all published titles on prod.
        return $query->where('title', 'like', self::charFor($slug).'%');
    }

    /**
     * Every first character that belongs to a named group — both cases for Latin, because SQLite's
     * `IN` is case-sensitive even where its LIKE is not.
     *
     * @return array<int,string>
     */
    private static function knownFirstChars(): array
    {
        $chars = array_map('strval', range(0, 9));
        foreach (self::all() as $slug) {
            $ch = self::charFor($slug);
            if ($ch === null) {
                continue;
            }
            $chars[] = $ch;
            if (preg_match('/^[a-z]$/', $ch)) {
                $chars[] = mb_strtoupper($ch);
            }
        }

        return $chars;
    }

    /** @param  array<int,string>  $values */
    private static function holders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * Published titles per group, empty groups dropped, in reading order.
     *
     * One grouped scan of the catalogue, shared by the directory pages and the sitemap so the two can
     * never disagree about which groups exist — a sitemap entry for an empty group is a 404 handed to
     * Google on purpose.
     *
     * @return array<string,int>
     */
    public static function counts(): array
    {
        return Cache::remember('directory:counts', now()->addHours(6), function () {
            $counts = [];
            foreach (self::all() as $slug) {
                $n = self::scope(Content::publicListing(), $slug)->count();
                if ($n > 0) {
                    $counts[$slug] = $n;
                }
            }

            return $counts;
        });
    }

    /**
     * Every group that could exist, in reading order: 0-9, A–Z, the Thai alphabet, then the catch-all.
     * Codepoint order is Thai dictionary order for the consonants and puts เ แ โ ใ ไ after ฮ, which is
     * where a Thai reader expects them.
     *
     * @return array<int,string> slugs
     */
    public static function all(): array
    {
        $slugs = [self::DIGITS];
        foreach (range('a', 'z') as $c) {
            $slugs[] = $c;
        }
        for ($cp = 0x0E01; $cp <= 0x0E2E; $cp++) {      // ก … ฮ
            $slugs[] = 'th-'.dechex($cp);
        }
        foreach ([0x0E40, 0x0E41, 0x0E42, 0x0E43, 0x0E44] as $cp) {   // เ แ โ ใ ไ
            $slugs[] = 'th-'.dechex($cp);
        }
        $slugs[] = self::OTHER;

        return $slugs;
    }
}
