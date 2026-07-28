<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentMirror;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Pairs duplicate titles across sources into mutual backup links. Owner (2026-07-28): "ถ้าเรื่องไหนซ้ำ
 * ให้ทำเป็นลิ้งค์สำรองของกันและกัน".
 *
 * Both catalogue rows are kept (nothing is merged or deleted — slugs, watch history and SEO stay put);
 * what's added is a [ContentMirror] in EACH direction, so whichever copy a viewer opens can fail over
 * to the other's stream via [MirrorRotation].
 *
 * A duplicate is same [Content::dedupeKey] AND a compatible year — a year gap is how remakes and
 * same-named sequels give themselves away, and cross-linking those would play the wrong film. When one
 * side has no year at all the titles are still paired: a missing year is far more common than a remake.
 */
class MirrorLinker
{
    /**
     * Link one title to every duplicate of it already in the catalogue. Runs at the end of an import,
     * so a title picked up from a second site immediately becomes a backup for the first.
     *
     * @return int mirrors created (both directions counted)
     */
    public static function linkTitle(Content $content): int
    {
        $key = Content::dedupeKey($content->title);
        if ($key === '' || ! $content->exists) {
            return 0;
        }

        // Indexed lookup on the stored key (contents.dedupe_key, kept in sync by Content's saving hook),
        // so this stays cheap even when an import walks thousands of titles in one run.
        $candidates = Content::withoutGlobalScopes()
            ->where('dedupe_key', $key)
            ->where('id', '!=', $content->id)
            ->where('type', $content->type)
            ->where('source', '!=', $content->source)
            ->get(['id', 'title', 'year', 'type', 'source', 'source_key']);

        $created = 0;
        foreach ($candidates as $other) {
            if (self::yearsAgree($content, $other)) {
                $created += self::pair($content, $other);
            }
        }

        return $created;
    }

    /**
     * Cross-link a whole group of duplicates (the netwix:link-mirrors backfill hands us one group per
     * dedupe key). Every member becomes a backup for every other member.
     *
     * @param  Collection<int,Content>  $group
     * @return int mirrors created
     */
    public static function linkGroup(Collection $group): int
    {
        $created = 0;
        $items = $group->values();

        for ($i = 0; $i < $items->count(); $i++) {
            for ($j = $i + 1; $j < $items->count(); $j++) {
                if (self::yearsAgree($items[$i], $items[$j])) {
                    $created += self::pair($items[$i], $items[$j]);
                }
            }
        }

        return $created;
    }

    /** Add A→B and B→A, skipping same-source pairs and anything already linked. */
    private static function pair(Content $a, Content $b): int
    {
        // Two copies on the SAME site share a CDN: if it's down, the "backup" is down with it.
        if ($a->source === $b->source || ! $a->source_key || ! $b->source_key) {
            return 0;
        }

        return self::add($a, $b) + self::add($b, $a);
    }

    /** One direction: $host gains a link to $other's copy. Returns 1 if a new row was written. */
    private static function add(Content $host, Content $other): int
    {
        $existing = ContentMirror::where('content_id', $host->id)->where('source', $other->source)->first();
        if ($existing) {
            // Same site, different remote id → the newer import is the better key, unless an admin
            // pinned this row by hand.
            if (! $existing->is_manual && $existing->source_key !== $other->source_key) {
                $existing->update(['source_key' => $other->source_key, 'fail_streak' => 0]);
            }

            return 0;
        }

        try {
            ContentMirror::create([
                'content_id' => $host->id,
                'source' => $other->source,
                'source_key' => $other->source_key,
                'priority' => 0,
            ]);
        } catch (QueryException) {
            return 0;   // two imports raced onto the same (content, source) pair — the unique index won
        }

        return 1;
    }

    /** Same year, or at least one side doesn't know its year. */
    private static function yearsAgree(Content $a, Content $b): bool
    {
        return ! $a->year || ! $b->year || (int) $a->year === (int) $b->year;
    }
}
