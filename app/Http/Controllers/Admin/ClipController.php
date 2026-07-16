<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateMarketingClip;
use App\Jobs\PostClipToFacebook;
use App\Models\ClipCampaignPost;
use App\Models\Content;
use App\Models\Episode;
use App\Models\MarketingClip;
use App\Support\CaptionWriter;
use App\Support\FacebookPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Marketing clip cutter. The admin picks a title/episode + a window (or asks for N
 * auto-spaced clips); we create MarketingClip rows and enqueue GenerateMarketingClip
 * jobs onto the `clips` queue (drained by the CLI workers — fpm can't spawn ffmpeg).
 * The page then polls `list` (per-clip status + preview) and `progress` (live agents).
 */
class ClipController extends Controller
{
    private const TTL_HOURS = 12;

    public function index(): View
    {
        $ready = MarketingClip::where('status', 'ready')->count();
        $posted = MarketingClip::whereNotNull('posted_at')->count();

        return view('admin.clips.index', compact('ready', 'posted'));
    }

    /** Title autocomplete (same shape as the cover generator). */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 1) {
            return response()->json(['items' => []]);
        }
        $items = Content::where('title', 'like', "%{$term}%")
            ->withCount('episodes')->orderByDesc('episodes_count')->take(12)
            ->get(['id', 'title'])
            ->map(fn (Content $c) => ['id' => $c->id, 'title' => $c->title, 'episodes' => $c->episodes_count]);

        return response()->json(['items' => $items]);
    }

    /** Episodes of a title, for the episode picker (shows length so a start makes sense). */
    public function episodes(Content $content): JsonResponse
    {
        $items = $content->episodes()->orderBy('sort')
            ->get(['id', 'number', 'title', 'duration_minutes'])
            ->map(fn (Episode $e) => [
                'id' => $e->id,
                'number' => $e->number,
                'title' => $e->title,
                'minutes' => $e->duration_minutes,
            ]);

        return response()->json(['title' => $content->title, 'items' => $items]);
    }

    /**
     * Create + enqueue one or more clips. Body:
     *   content_id (req), episode_id (opt), duration, aspect, count (1-5), start (opt).
     * With an explicit start → one clip there. Otherwise → `count` clips auto-spaced
     * across the episode (skipping the intro/credits margins).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'episode_id' => ['nullable', 'integer', 'exists:episodes,id'],
            'duration' => ['required', 'integer', 'min:5', 'max:180'],
            'aspect' => ['required', 'in:9:16,1:1,16:9'],
            'count' => ['nullable', 'integer', 'min:1', 'max:5'],
            'start' => ['nullable', 'integer', 'min:0'],
        ]);

        $episode = ! empty($data['episode_id'])
            ? Episode::where('content_id', $data['content_id'])->find($data['episode_id'])
            : Content::find($data['content_id'])?->episodes()->orderBy('sort')->first();

        $starts = $this->pickStarts(
            explicit: $request->filled('start') ? (int) $data['start'] : null,
            count: (int) ($data['count'] ?? 1),
            duration: (int) $data['duration'],
            episodeMinutes: $episode?->duration_minutes,
        );

        $batch = 'c'.Str::random(9);
        $this->openBatch($batch, count($starts));

        foreach ($starts as $start) {
            $clip = MarketingClip::create([
                'content_id' => $data['content_id'],
                'episode_id' => $episode?->id,
                'start' => $start,
                'duration' => $data['duration'],
                'aspect' => $data['aspect'],
                'status' => 'pending',
                'batch_id' => $batch,
            ]);
            GenerateMarketingClip::dispatch($clip->id, $batch)->onQueue('clips');
        }

        return response()->json(['batch' => $batch, 'count' => count($starts)]);
    }

    /** Recent clips (newest first) for the gallery — polled while a batch runs. */
    public function list(Request $request): JsonResponse
    {
        $clips = MarketingClip::with('content:id,title,slug', 'episode:id,number')
            ->latest()->take(40)->get();

        // A campaign's post failure lives on its ClipCampaignPost row, which this page never read —
        // so a clip whose auto-post died sat here looking like a healthy "พร้อม" clip forever, with
        // nothing telling the admin it needs a re-post. Oldest-first so keyBy keeps the NEWEST row.
        $campaignErrors = ClipCampaignPost::whereIn('marketing_clip_id', $clips->pluck('id'))
            ->where('status', 'failed')
            ->whereNotNull('error')
            ->oldest('id')
            ->get(['marketing_clip_id', 'error'])
            ->keyBy('marketing_clip_id');

        $clips = $clips
            ->map(fn (MarketingClip $c) => [
                'id' => $c->id,
                'title' => $c->content?->title ?? '—',
                'episode' => $c->episode?->number,
                'start' => $c->start,
                'duration' => $c->duration,
                'aspect' => $c->aspect,
                'status' => $c->status,
                'error' => $c->error,
                'file_url' => $c->file_url,
                'poster_url' => $c->poster_url,
                'caption' => $c->caption,
                'scheduled_at' => $c->scheduled_at?->toDateTimeString(),
                'posted_at' => $c->posted_at?->toDateTimeString(),
                // Repost UI: purged clips keep status=ready but lose their file, and a repost's
                // only visible outcome is posted_at moving — so both must reach the gallery.
                'purged' => (bool) $c->files_purged_at,
                'repost_count' => (int) ($c->meta['repost_count'] ?? 0),
                // A manual attempt's verdict wins — it's the newer, more specific one; otherwise
                // fall back to the campaign's failure so an auto-post that died is still visible.
                'post_error' => $c->meta['last_post_error']
                    ?? ($c->posted_at ? null : $campaignErrors[$c->id]?->error),
                'post_partial' => (bool) ($c->meta['last_post_partial'] ?? false),
            ]);

        return response()->json(['clips' => $clips]);
    }

    /** Live batch progress + one entry per working agent. */
    public function progress(Request $request): JsonResponse
    {
        $batch = (string) $request->query('batch', '');
        $total = (int) Cache::get("clips:{$batch}:total", 0);
        $proc = (int) Cache::get("clips:{$batch}:proc", 0);
        $fail = (int) Cache::get("clips:{$batch}:fail", 0);

        return response()->json([
            'total' => $total,
            'processed' => $proc,
            'failed' => $fail,
            'done' => $total > 0 && $proc >= $total,
            'last' => Cache::get("clips:{$batch}:last"),
            'agents' => $this->activeAgents(),
        ]);
    }

    /** Edit the caption / schedule of a clip (used before posting). */
    public function update(Request $request, MarketingClip $clip): JsonResponse
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        $clip->update($data);

        return response()->json(['ok' => true]);
    }

    /** (Re)write the caption with the AI/template writer and save it. */
    public function caption(MarketingClip $clip, CaptionWriter $writer): JsonResponse
    {
        $caption = $writer->for($clip);
        $clip->update(['caption' => $caption]);

        return response()->json(['caption' => $caption]);
    }

    /** Re-cut a failed/ready clip with its existing window. */
    public function retry(MarketingClip $clip): JsonResponse
    {
        $batch = 'c'.Str::random(9);
        $this->openBatch($batch, 1);
        $clip->update(['status' => 'pending', 'error' => null, 'batch_id' => $batch]);
        GenerateMarketingClip::dispatch($clip->id, $batch)->onQueue('clips');

        return response()->json(['batch' => $batch]);
    }

    /**
     * Publish a finished clip to the Facebook page on the admin's explicit say-so — the first
     * time, or again ("รีโพส"). Campaign clips auto-post themselves; a hand-cut clip has no
     * campaign, so without this button it could never reach the page at all.
     *
     * Refuses rather than degrades: an unconnected page would make the publisher fall back to a
     * silent dry-run, and a purged clip would fail deep in the queue as `no_file_url`. Both are
     * knowable here, so the admin is told now instead of watching a button do nothing.
     */
    public function repost(Request $request, MarketingClip $clip, FacebookPublisher $fb): JsonResponse
    {
        $data = $request->validate(['known_posted_at' => ['nullable', 'string']]);

        // The gallery stops polling once nothing is cutting, so its rows can be arbitrarily stale —
        // a campaign may have published this very clip since the page loaded. The admin would then
        // be confirming "โพสต์" against a clip that is already live, and get a silent duplicate.
        // So: only act if the admin's screen agrees with the DB, else make them look again.
        $posted = $clip->posted_at?->toDateTimeString();
        if ($posted !== ($data['known_posted_at'] ?? null)) {
            return response()->json([
                'stale' => true,
                'error' => $posted
                    ? "คลิปนี้ถูกโพสต์ไปแล้วเมื่อ {$posted} — หน้าจอยังไม่อัปเดต\nรีเฟรชแล้วกด “รีโพส” อีกครั้งถ้าต้องการโพสต์ซ้ำจริงๆ"
                    : 'สถานะคลิปเปลี่ยนไปแล้ว — รีเฟรชหน้าก่อนแล้วลองใหม่',
            ], 409);
        }

        if (! $fb->enabled()) {
            return response()->json([
                'error' => 'ยังไม่ได้เชื่อมต่อ Facebook — ไปเชื่อมต่อเพจที่หน้าตั้งค่าก่อน',
            ], 422);
        }
        if (! $clip->is_ready) {
            return response()->json([
                'error' => $clip->files_purged_at
                    ? 'ไฟล์คลิปถูกลบไปแล้ว (ระบบเก็บไฟล์ 15 วัน) — กด “↻ ตัดใหม่” ให้สร้างไฟล์ก่อน แล้วค่อยโพสต์'
                    : 'คลิปนี้ยังไม่พร้อมโพสต์',
            ], 422);
        }

        // Belt-and-braces against a burst of presses. Today the `clips-post` worker is single and
        // withoutOverlapping, so the job's own guard already serialises them; this holds even if
        // that ever goes concurrent. It expires on its own — so the message says "just sent one",
        // not "still posting", which it cannot know.
        if (! Cache::lock("clips:repost:{$clip->id}", 180)->get()) {
            return response()->json(['error' => 'เพิ่งสั่งโพสต์คลิปนี้ไปเมื่อครู่ — รอสักครู่แล้วลองใหม่'], 429);
        }

        $meta = $clip->meta ?? [];
        // Stale verdict from a previous attempt → the page would call THIS run failed on sight.
        unset($meta['last_post_error'], $meta['last_post_partial']);

        // A 9:16 cut exists to be a Reel; anything else belongs in the feed. Only fills a blank —
        // campaign clips carry their campaign's targets, and the admin's own edit is kept.
        $clip->update([
            'meta' => $meta,
            'post_targets' => blank($clip->post_targets)
                ? ($clip->aspect === '9:16' ? 'reels' : 'feed')
                : $clip->post_targets,
        ]);

        PostClipToFacebook::dispatch($clip->id, now()->toIso8601String())->onQueue('clips-post');

        return response()->json([
            'ok' => true,
            'repost' => (bool) $clip->posted_at,
            'targets' => $clip->post_targets,
            'page' => $fb->pageName(),
        ]);
    }

    public function destroy(MarketingClip $clip): JsonResponse
    {
        foreach ([$clip->file_path, $clip->poster_path] as $path) {
            if ($path) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (Throwable $e) {
                    // best-effort file cleanup
                }
            }
        }
        $clip->delete();

        return response()->json(['ok' => true]);
    }

    public function stop(Request $request): JsonResponse
    {
        $batch = (string) $request->input('batch');
        if ($batch !== '') {
            Cache::put("clips:{$batch}:stop", true, now()->addHours(self::TTL_HOURS));
        }

        return response()->json(['ok' => true]);
    }

    // ---- internals ---------------------------------------------------------

    /**
     * Decide the start timestamp(s). An explicit start wins (single clip). Otherwise
     * spread `count` clips evenly across the watchable middle of the episode — skip the
     * first/last ~8% so we don't clip the intro card or the end credits. Falls back to a
     * safe fixed ladder when the episode length is unknown.
     *
     * @return array<int, int>  seconds
     */
    private function pickStarts(?int $explicit, int $count, int $duration, ?int $episodeMinutes): array
    {
        if ($explicit !== null) {
            return [$explicit];
        }
        $count = max(1, min(5, $count));
        $total = $episodeMinutes ? $episodeMinutes * 60 : 0;

        if ($total < $duration + 60) {
            // Unknown/short length → a conservative ladder from the 2-minute mark.
            return array_map(fn ($i) => 120 + $i * ($duration + 90), range(0, $count - 1));
        }

        $margin = (int) round($total * 0.08);
        $lo = $margin;
        $hi = max($lo + $duration, $total - $margin - $duration);
        if ($count === 1) {
            return [(int) round(($lo + $hi) / 2)];   // one clip → the middle
        }
        $step = ($hi - $lo) / ($count - 1);

        return array_map(fn ($i) => (int) round($lo + $i * $step), range(0, $count - 1));
    }

    private function openBatch(string $batch, int $total): void
    {
        $ttl = now()->addHours(self::TTL_HOURS);
        Cache::put("clips:{$batch}:total", $total, $ttl);
        Cache::put("clips:{$batch}:proc", 0, $ttl);
        Cache::put("clips:{$batch}:fail", 0, $ttl);
        Cache::forget("clips:{$batch}:stop");
    }

    /** Currently-working agents from the shared activity hash (drops stale PIDs). */
    private function activeAgents(): array
    {
        $agents = [];
        try {
            $raw = Redis::hgetall('netwix:clips:agents') ?: [];
            $now = time();
            $stale = [];
            foreach ($raw as $pid => $json) {
                $a = json_decode((string) $json, true);
                if (! is_array($a) || $now - (int) ($a['ts'] ?? 0) > 12) {
                    $stale[] = $pid;

                    continue;
                }
                $agents[] = ['label' => $a['label'] ?? '—', 'done' => (int) ($a['done'] ?? 0)];
            }
            if ($stale) {
                Redis::hdel('netwix:clips:agents', ...$stale);
            }
            usort($agents, fn ($x, $y) => $y['done'] <=> $x['done']);
        } catch (Throwable $e) {
            // best-effort
        }

        return $agents;
    }
}
