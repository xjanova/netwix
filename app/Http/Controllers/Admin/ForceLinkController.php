<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\SourceTitle;
use App\Services\Import\Contracts\BackupPoolSource;
use App\Services\Import\SourceRegistry;
use App\Support\BackupFinder;
use App\Support\PlaybackHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "บังคับอัพเดทลิ้งค์หนัง" — manual force-update of a title's stream link. The admin searches a title in
 * OUR catalogue, picks ONE backup-pool site ([SourceRegistry::backupPool]), searches that site for the
 * same title, and force-applies a chosen result's link. Unlike the automatic netwix:find-backups bot
 * (which only fills a fallback for dead links), a forced link is played FIRST — it overrides even a
 * still-working primary (episodes.backup_forced, honoured in StreamController / EpisodeSourceController).
 *
 * All three steps are JSON endpoints driven by the Alpine page (admin/force-link/index.blade.php).
 */
class ForceLinkController extends Controller
{
    public function __construct(
        private SourceRegistry $registry,
        private BackupFinder $finder,
    ) {}

    public function index(): View
    {
        return view('admin.force-link.index', [
            'poolSites' => collect($this->registry->backupPool())
                ->map(fn (BackupPoolSource $s) => ['id' => $s->id(), 'name' => $s->displayName()])
                ->values()->all(),
        ]);
    }

    /** Step 1 — find a title in OUR catalogue by name (published or not). */
    public function searchTitles(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = Content::query()
            ->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%"))
            ->withCount('episodes')
            ->with(['episodes' => fn ($e) => $e->select('id', 'content_id', 'backup_source', 'backup_forced')])
            ->orderByDesc('is_published')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(function (Content $c) {
                $forced = $c->episodes->firstWhere('backup_forced', true);

                return [
                    'id' => $c->id,
                    'title' => $c->title,
                    'type' => $c->type,
                    'type_label' => ['series' => 'ซีรี่ส์', 'movie' => 'ภาพยนตร์', 'vertical' => 'แนวตั้ง'][$c->type] ?? $c->type,
                    'year' => $c->year,
                    'poster' => $c->poster_url,
                    'source' => $c->source ?: '—',
                    'episodes' => $c->episodes_count,
                    'published' => (bool) $c->is_published,
                    'suspended' => $c->suspended_at !== null,
                    'forced_site' => $forced ? ($this->registry->get($forced->backup_source)?->displayName() ?? $forced->backup_source) : null,
                    'watch_url' => route('watch', $c),
                ];
            });

        return response()->json(['results' => $results]);
    }

    /**
     * Step 2 — find the title on the CHOSEN pool site, returning candidate links to force. Searches the
     * LOCALLY SYNCED catalogue (`source_titles`) rather than the live site: it's instant, uniform across
     * every pool site, and the only option for 9.9nung (whose own search DB-errors). The site must have
     * been synced first (admin.import "ซิงค์แคตตาล็อก") — `synced` tells the UI when it's empty.
     */
    public function searchSite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site' => ['required', 'string'],
            'q' => ['required', 'string', 'min:2'],
        ]);
        $source = $this->poolSite($data['site']);
        if (! $source) {
            return response()->json(['results' => [], 'error' => 'ไม่พบเว็บสำรองนี้ในระบบ'], 404);
        }

        $q = trim($data['q']);
        $results = SourceTitle::where('source', $source->id())
            ->where(fn ($w) => $w->where('clean_title', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%"))
            ->orderByDesc('view_count')->orderByDesc('id')
            ->limit(15)->get()
            ->map(fn (SourceTitle $st) => [
                'key' => $st->source_key,
                'title' => $st->displayTitle(),
                'raw_title' => $st->title,
                'year' => $st->year,
                'poster' => $st->poster_url,
                'is_movie' => (bool) (is_array($st->extra) ? ($st->extra['is_movie'] ?? true) : true),
            ])->values();

        return response()->json([
            'results' => $results,
            'site' => $source->displayName(),
            'synced' => SourceTitle::where('source', $source->id())->count(),
        ]);
    }

    /**
     * Step 3 — force this pool site's link onto every episode of the title. Verifies the stream really
     * plays first (master playlist + a real MPEG-TS segment) unless the admin confirms an override, then
     * points all episodes at it (backup_forced = true) and re-publishes if the title was suspended.
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'integer', 'exists:contents,id'],
            'site' => ['required', 'string'],
            'key' => ['required', 'string', 'max:64'],
            'skip_verify' => ['sometimes', 'boolean'],
        ]);

        $source = $this->poolSite($data['site']);
        if (! $source) {
            return response()->json(['ok' => false, 'message' => 'ไม่พบเว็บสำรองนี้ในระบบ'], 404);
        }

        $content = Content::with('episodes')->findOrFail($data['content']);

        // Verify episode 1 actually plays on the chosen site before committing (the remote post id
        // resolves every episode of a series via its own `episode` param, so ep1 is representative).
        if (! $request->boolean('skip_verify')) {
            $stream = $this->finder->resolveVerified($source, $data['key'], '1');
            if ($stream === null) {
                return response()->json([
                    'ok' => false,
                    'need_confirm' => true,
                    'message' => 'ลองเล่นลิ้งค์จาก '.$source->displayName().' แล้วยังเล่นไม่ได้ในตอนนี้ — จะบังคับใช้เลยไหม?',
                ]);
            }
        }

        $isMovie = $content->type === 'movie';
        DB::transaction(function () use ($content, $source, $data, $isMovie) {
            foreach ($content->episodes as $ep) {
                $ep->forceFill([
                    'backup_source' => $source->id(),
                    'backup_key' => $data['key'],
                    'backup_ref' => $isMovie ? '1' : (string) $ep->number,
                    'backup_forced' => true,
                ])->save();
                $this->forgetStreamCaches($ep->id);
            }
        });

        // A forced link is meant to make the title playable now → clear the review flag and un-suspend
        // if it was parked. A never-published draft is left unpublished (forcing a link shouldn't
        // publish a draft) — just its stale review flag is cleared.
        if ($content->suspended_at || $content->is_published) {
            PlaybackHealth::republish($content);
        } elseif ($content->review_flagged_at) {
            $content->forceFill(['review_flagged_at' => null])->save();
        }

        return response()->json([
            'ok' => true,
            'message' => 'บังคับใช้ลิ้งค์จาก '.$source->displayName().' กับ "'.$content->title.'" แล้ว ('.$content->episodes->count().' ตอน)',
            'site' => $source->displayName(),
        ]);
    }

    /** Undo a forced link — revert priority back to the title's own primary source. */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'integer', 'exists:contents,id']]);
        $content = Content::with('episodes')->findOrFail($data['content']);

        foreach ($content->episodes as $ep) {
            if (! $ep->backup_forced) {
                continue;
            }
            // Keep backup_source/key/ref as a passive fallback (same as the bot leaves) — only drop the
            // "play this first" override so the primary source takes over again.
            $ep->forceFill(['backup_forced' => false])->save();
            $this->forgetStreamCaches($ep->id);
        }

        return response()->json(['ok' => true, 'message' => 'ยกเลิกการบังคับลิ้งค์แล้ว — กลับไปใช้ต้นทางเดิม']);
    }

    private function poolSite(string $id): ?BackupPoolSource
    {
        return $this->registry->backupPool()[$id] ?? null;
    }

    /** Drop the per-episode resolved-stream caches so the new link takes effect immediately. */
    private function forgetStreamCaches(int $episodeId): void
    {
        Cache::forget("ep_raw:{$episodeId}");
        Cache::forget("episode:src:{$episodeId}");
        Cache::forget("episode:src:{$episodeId}:miss");
    }
}
