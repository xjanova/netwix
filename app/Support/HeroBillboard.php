<?php

namespace App\Support;

use App\Http\Controllers\StreamController;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Setting;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the rotating "หนังตัวอย่าง" billboard payload (title metadata + a random-episode pool per
 * title) for every surface that shows one: the member home + guest landing draw from the WHOLE site
 * ('all'); each big category hub ('movie'|'series'|'anime'|'vertical') draws only from its own type.
 *
 * SCALE: the payload for a surface is cached for ~2 minutes and SHARED by every visitor in that
 * window — so 100 guests/sec run the random pool query ONCE, not once each. Variety still holds: the
 * pool holds up to POOL titles and the client starts at a random index + rotates, so concurrent
 * visitors see different titles from the same cached set. The heavy per-viewer step (resolving a live
 * stream) is deferred to the client and cached per-episode by EpisodeSourceController — "คนก่อนหน้า"
 * warms an episode's URL and "คนต่อไป" gets the cache hit.
 */
class HeroBillboard
{
    /** Titles cached per surface. Client rotates through them → variety without a per-request query. */
    private const POOL = 15;

    /** Random-episode URLs sampled per title (client picks a fresh one each rotation → "สุ่มตอน"). */
    private const EPS_PER = 12;

    /** Seconds a surface's slides stay cached (shared by every visitor in the window). */
    private const TTL = 120;

    /** Rotation interval per slide — admin-set (same field the home hero already used). */
    public static function seconds(): int
    {
        return max(0, (int) Setting::get('home_hero_seconds', 8));
    }

    /**
     * Master kill-switch for the VIDEO layer. Off → the billboard still rotates its backdrops + text
     * (cheap, cacheable), but no stream is resolved or played. Flip this if load ever spikes.
     */
    public static function videoEnabled(): bool
    {
        return Setting::flag('preview_billboard_enabled', true);
    }

    /** Cached slide payload for a surface. Empty array when nothing is showable (never 500s a page). */
    public static function slides(string $surface): array
    {
        return Cache::remember("hero:slides:{$surface}", now()->addSeconds(self::TTL),
            fn () => self::build($surface));
    }

    /** Drop every cached surface (call after an import/publish so a new title can appear promptly). */
    public static function forget(): void
    {
        foreach (['all', 'movie', 'series', 'anime', 'vertical'] as $s) {
            Cache::forget("hero:slides:{$s}");
        }
    }

    private static function build(string $surface): array
    {
        $pool = self::pool($surface);
        if ($pool->isEmpty()) {
            return [];
        }

        $eps = self::episodeUrls($pool->pluck('id')->all());

        return $pool->map(fn (Content $c) => [
            'title' => $c->title,
            'year' => $c->year,
            'maturity' => $c->maturity,
            'meta' => $c->type === 'movie'
                ? (($c->duration_minutes ?: 0).' นาที')
                : (($c->seasons_count ?: 1).' ซีซั่น'),
            'match' => $c->match_score,
            'dub' => $c->dub_label,
            'synopsis' => $c->synopsis,
            'backdrop' => $c->backdrop_url,
            'gradient' => $c->gradient,
            'original' => (bool) $c->is_original,
            // Both target sets travel in the payload; the partial picks by surface (public vs member),
            // so one cached blob serves the member home AND the guest landing.
            'show' => route('title.show', $c),      // public title page (guest/crawler safe)
            'watch' => route('watch', $c),          // member player
            'modal' => route('title.modal', $c),    // member quick-look
            'clip' => $c->preview_url,              // stored ep1 mp4 (cheap fallback) or null
            'eps' => $eps[$c->id] ?? [],           // ready public URLs → client plays a random one each rotation
        ])->values()->all();
    }

    /** The candidate titles for a surface. publicListing() keeps adult (18+/20+) + suspended OUT. */
    private static function pool(string $surface): Collection
    {
        $base = fn () => Content::publicListing()->withCount('seasons')->with(['genres', 'previewEpisode']);

        if ($surface === 'all') {
            // Home + landing = the WHOLE site (random). An admin can still pin a source in Settings;
            // an unset source (the default) means whole-site random, which is what the owner wants.
            $src = (string) Setting::get('home_hero_source', '');
            if ($src === 'trending') {
                return $base()->trending()->take(self::POOL)->get();
            }
            if (str_starts_with($src, 'genre:')) {
                $gid = (int) substr($src, 6);

                return $base()->whereHas('genres', fn ($g) => $g->where('genres.id', $gid))
                    ->inRandomOrder()->take(self::POOL)->get();
            }
            if ($src === 'featured') {
                $pinned = $base()->where('is_featured', true)->inRandomOrder()->take(self::POOL)->get();
                if ($pinned->isNotEmpty()) {
                    return $pinned;
                }
            }

            return $base()->inRandomOrder()->take(self::POOL)->get();
        }

        if ($surface === 'anime') {
            $ids = self::animeGenreIds();

            return $ids === []
                ? collect()
                : $base()->whereHas('genres', fn ($g) => $g->whereIn('genres.id', $ids))
                    ->where('type', '!=', 'vertical')   // anime billboard = series/movies only, no shorts
                    ->inRandomOrder()->take(self::POOL)->get();
        }

        // movie / series / vertical
        return $base()->type($surface)->inRandomOrder()->take(self::POOL)->get();
    }

    /**
     * PUBLIC, ready-to-play URL pool per content id — sampled EVENLY across the run (not the first 12)
     * so "random episode" really spans the whole series. These are built server-side and cached, so the
     * CLIENT never calls the (auth-gated) resolver: guests play them directly, and HLS goes through the
     * cookieless, token-gated, EDGE-CACHEABLE manifest proxy — the single biggest scale win when many
     * visitors hit the same title at once. Only non-adult titles reach here (publicListing), so minting
     * manifest tokens here can't leak a Pro/adult stream.
     *
     * @param  int[]  $contentIds
     * @return array<int, string[]>
     */
    private static function episodeUrls(array $contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        $registry = app(SourceRegistry::class);

        return Episode::whereIn('content_id', $contentIds)
            ->where(fn ($q) => $q->whereNotNull('video_url')->orWhereNotNull('source'))
            ->orderBy('content_id')->orderBy('season_id')->orderBy('number')
            ->get(['id', 'content_id', 'video_url', 'source', 'source_ref'])
            ->groupBy('content_id')
            ->map(function (Collection $group) use ($registry) {
                $eps = $group->values();
                if ($eps->count() > self::EPS_PER) {
                    $step = $eps->count() / self::EPS_PER;
                    $eps = collect(range(0, self::EPS_PER - 1))
                        ->map(fn ($i) => $eps[(int) floor($i * $step)]);
                }

                return $eps->map(fn (Episode $e) => self::readyUrl($e, $registry))->filter()->values()->all();
            })
            ->all();
    }

    /** A public, directly-playable URL for an episode (no client resolve). Null when nothing's playable. */
    private static function readyUrl(Episode $ep, SourceRegistry $registry): ?string
    {
        if ($ep->video_url) {
            return $ep->video_url;   // manual/stored — directly playable, exactly what the resolver hands out
        }
        if (! $ep->source) {
            return null;
        }
        $src = $registry->get($ep->source);
        if ($src && ! $src->isProgressive()) {
            // HLS (anime108 / wow-drama): cookieless, token-gated, edge-cacheable manifest proxy.
            return route('stream.manifest', $ep).'?t='.StreamController::token($ep);
        }

        // Progressive (rongyok signed mp4): the token-gated mp4 proxy resolves the signed URL server-side
        // and honours Range, so a random-seek preview only pulls its window — never the whole file. Mint
        // the same short-lived token manifest() uses (the route is no longer open — see StreamController::mp4).
        return $ep->source_ref ? route('stream.mp4', $ep).'?t='.StreamController::token($ep) : null;
    }

    /** @return int[] genre ids for the anime/cartoon umbrella (cached — it barely changes). */
    private static function animeGenreIds(): array
    {
        return Cache::remember('public:anime-genre-ids', 3600,
            fn () => Genre::whereIn('name', ['อนิเมะ', 'การ์ตูน'])->pluck('id')->all());
    }
}
