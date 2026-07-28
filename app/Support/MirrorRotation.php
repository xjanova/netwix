<?php

namespace App\Support;

use App\Models\ContentMirror;
use App\Models\Episode;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plays an episode through a ROTATION of links instead of a single source. Owner (2026-07-28):
 * duplicate titles become each other's backups; when a link won't open, move to the next one, and
 * when the whole cycle has been tried the title counts as dead and is auto-unpublished.
 *
 * The chain, in order:
 *   1. a manually FORCED backup (admin "บังคับอัพเดทลิ้งค์") — an explicit human decision wins outright
 *   2. the link that last played this episode (`episodes.active_mirror_id`), so a title that already
 *      failed over doesn't restart at its known-dead primary on every cache miss
 *   3. the title's own source
 *   4. every [ContentMirror], admin pins first, then whichever has been failing least
 * Duplicates are collapsed, so a link that's both "active" and "primary" is only tried once.
 *
 * Each attempt updates that link's health, and a full cycle of DEFINITIVE failures hands the title to
 * [PlaybackHealth::declareDead]. A failure that merely threw (network/DNS) is treated as transient and
 * suppresses the death — an outage must never mass-unpublish the catalogue. [PlaybackHealth] keeps a
 * second rail (DEAD_BUDGET) for the case where a whole upstream answers cleanly but wrongly.
 *
 * Resolution is cached per-episode (the same `ep_raw` key the stream proxy has always used) so a walk
 * happens at most once every 10 minutes per episode, not once per manifest/segment request.
 */
class MirrorRotation
{
    /** @return array{stream:RemoteStream,link:MirrorLink}|null */
    public static function resolve(Episode $episode, SourceRegistry $registry): ?array
    {
        $key = "ep_raw:{$episode->id}";

        // A whole cycle just failed — hold off briefly. The player polls "preparing" every couple of
        // seconds, and without this every poll would re-walk the entire chain against every upstream.
        if (Cache::get($key.':miss')) {
            return null;
        }

        $cached = Cache::get($key);
        if (! is_array($cached) || ! isset($cached['url'])) {
            $cached = self::walk($episode, $registry);
            if (! is_array($cached)) {
                Cache::put($key.':miss', 1, now()->addSeconds(15));

                return null;
            }
            Cache::put($key, $cached, now()->addSeconds(self::cacheSeconds((string) $cached['url'])));
        }

        return [
            'stream' => new RemoteStream($cached['kind'], $cached['url'], $cached['referer'] ?? null),
            'link' => new MirrorLink(
                $cached['mirror_id'] ?? null,
                (string) $cached['source'],
                (string) $cached['key'],
                (string) $cached['ref'],
                (bool) ($cached['primary'] ?? false),
            ),
        ];
    }

    /**
     * How long a resolved link stays cached. Ten minutes by default (HLS sources hand out stable
     * playlist URLs), but a SIGNED url carries its own expiry and re-resolving it needlessly is what
     * gets our IP rate-limited — so those are kept until an hour before they die. Two known formats:
     * Discord's `ex=<hex seconds>` (rongyok) and `e=<decimal seconds>` (anifume/rukoluo).
     */
    private static function cacheSeconds(string $url): int
    {
        if (preg_match('~[?&]ex=([0-9a-f]+)~i', $url, $m)) {
            return max(60, min(6 * 3600, (int) hexdec($m[1]) - time() - 3600));
        }
        if (preg_match('~[?&]e=(\d{10})(?:&|$)~', $url, $m)) {
            return max(60, min(6 * 3600, (int) $m[1] - time() - 3600));
        }

        return 600;
    }

    /**
     * Try every link in turn. Returns the cacheable payload of the first that resolves, or null once
     * the cycle is exhausted (declaring the title dead when every failure was definitive).
     *
     * @return array<string,mixed>|null
     */
    private static function walk(Episode $episode, SourceRegistry $registry): ?array
    {
        $links = self::chain($episode);
        if ($links === []) {
            return null;
        }

        $transient = false;
        $attempted = 0;

        foreach ($links as $link) {
            $source = $registry->get($link->source);
            if (! $source || $link->key === '' || $link->ref === '') {
                continue;   // source removed from the registry / incomplete row — not a link failure
            }
            // Skip a link that resolved fine but then served a dead playlist (see [self::markDead]) —
            // that's the case a plain resolve() can't detect, and without this the chain would keep
            // handing back the same broken link instead of rotating past it.
            if (Cache::get(self::deadKey($episode, $link))) {
                continue;
            }
            $attempted++;

            try {
                $stream = $source->resolveByRef($link->key, $link->ref);
            } catch (\Throwable $e) {
                // Threw rather than answered → upstream/network trouble, not proof the link is dead.
                $transient = true;
                $stream = null;
            }

            if ($stream !== null) {
                self::recordOk($episode, $link);

                return [
                    'kind' => $stream->kind,
                    'url' => $stream->url,
                    'referer' => $stream->referer,
                    'mirror_id' => $link->mirrorId,
                    'source' => $link->source,
                    'key' => $link->key,
                    'ref' => $link->ref,
                    'primary' => $link->primary,
                ];
            }

            self::recordFailure($link);
        }

        // Full cycle, nothing played. Only call that death when every link gave a clean "no stream" —
        // never off the back of a transient error, and never when the whole chain happened to be
        // sitting in the short dead-link cooldown (nothing was actually tried).
        $content = $episode->content;
        if ($content && ! $transient && $attempted > 0) {
            Log::warning('playback: every link failed — declaring title dead', [
                'content_id' => $content->id, 'title' => $content->title,
                'episode_id' => $episode->id, 'links' => count($links),
            ]);
            PlaybackHealth::declareDead($content, 'all_links_failed');
        }

        return null;
    }

    /**
     * The ordered, de-duplicated chain for one episode.
     *
     * @return MirrorLink[]
     */
    public static function chain(Episode $episode): array
    {
        $content = $episode->content;
        if (! $content) {
            return [];
        }

        // A mirror is the SAME title on another site, so its episode ref is this episode's number
        // (movies always resolve on "1") — the remote id in source_key covers every episode.
        $mirrorRef = $content->type === 'movie' ? '1' : (string) $episode->number;

        $ordered = [];

        // 1. Admin-forced backup — kept as-is so the existing force-link flow behaves exactly as before.
        if ($episode->backup_forced && $episode->backup_source && $episode->backup_key) {
            $ordered[] = new MirrorLink(
                null,
                (string) $episode->backup_source,
                (string) $episode->backup_key,
                (string) ($episode->backup_ref ?: $episode->source_ref),
            );
        }

        $mirrors = $content->relationLoaded('mirrors')
            ? $content->mirrors->sortBy([['is_manual', 'desc'], ['fail_streak', 'asc'], ['priority', 'asc'], ['id', 'asc']])
            : $content->mirrors()->inChainOrder()->get();

        // 2. Whatever played last time.
        if ($episode->active_mirror_id) {
            $active = $mirrors->firstWhere('id', $episode->active_mirror_id);
            if ($active) {
                $ordered[] = new MirrorLink($active->id, $active->source, $active->source_key, $mirrorRef);
            }
        }

        // 3. The title's own source.
        if ($episode->source && $episode->source_ref && $content->source_key) {
            $ordered[] = new MirrorLink(null, (string) $episode->source, (string) $content->source_key,
                (string) $episode->source_ref, primary: true);
        }

        // 4. The rest of the mirrors.
        foreach ($mirrors as $mirror) {
            $ordered[] = new MirrorLink($mirror->id, $mirror->source, $mirror->source_key, $mirrorRef);
        }

        // 5. Legacy passive backup_* triple (what netwix:find-backups writes) — last, and only if it
        //    isn't already in the chain as a mirror row.
        if (! $episode->backup_forced && $episode->backup_source && $episode->backup_key) {
            $ordered[] = new MirrorLink(
                null,
                (string) $episode->backup_source,
                (string) $episode->backup_key,
                (string) ($episode->backup_ref ?: $mirrorRef),
            );
        }

        $seen = [];
        $chain = [];
        foreach ($ordered as $link) {
            $fp = $link->fingerprint();
            if (! isset($seen[$fp])) {
                $seen[$fp] = true;
                $chain[] = $link;
            }
        }

        return $chain;
    }

    /**
     * The link resolved, but what it handed back turned out not to be playable (the stream proxy
     * fetched the playlist and got junk / a 403 page). resolveByRef can't see that, so the proxy calls
     * this: it benches the link for a few minutes, drops the cached resolution, and lets the very next
     * request rotate on to the next link in the chain.
     */
    public static function markDead(Episode $episode, MirrorLink $link): void
    {
        Cache::put(self::deadKey($episode, $link), 1, now()->addMinutes(10));
        Cache::forget("ep_raw:{$episode->id}");
        Cache::forget("ep_raw:{$episode->id}:miss");   // let the retry happen now, not in 15s
        Cache::forget("ep_manifest:{$episode->id}");
        self::recordFailure($link);
    }

    private static function deadKey(Episode $episode, MirrorLink $link): string
    {
        return "ep_deadlink:{$episode->id}:".sha1($link->fingerprint());
    }

    /** This link played: clear its failure streak and remember it as the episode's active link. */
    private static function recordOk(Episode $episode, MirrorLink $link): void
    {
        try {
            if ($link->mirrorId !== null) {
                ContentMirror::whereKey($link->mirrorId)
                    ->update(['last_ok_at' => now(), 'fail_streak' => 0]);
            }
            // Pin the winner (null for the primary, which is where the chain starts by default).
            if ($episode->active_mirror_id !== $link->mirrorId) {
                Episode::whereKey($episode->id)->update(['active_mirror_id' => $link->mirrorId]);
                $episode->active_mirror_id = $link->mirrorId;
            }
        } catch (\Throwable $e) {
            // health bookkeeping must never break playback
        }
    }

    private static function recordFailure(MirrorLink $link): void
    {
        if ($link->mirrorId === null) {
            return;   // the primary and the legacy triple have no row to score
        }
        try {
            ContentMirror::whereKey($link->mirrorId)->update([
                'last_failed_at' => now(),
                'fail_streak' => DB::raw('fail_streak + 1'),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
