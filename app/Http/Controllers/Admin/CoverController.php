<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Support\ImageStore;
use App\Support\PosterBackfill;
use App\Support\PosterSearch;
use App\Support\SafeUrl;
use App\Support\SiteSearchScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "ปกที่หายไป" — the work-list of titles without a usable cover, and the tools to give them one.
 *
 * A missing cover has always been invisible in the admin: the card quietly falls back to the branded
 * gradient, the automatic heal ([App\Http\Controllers\PosterHealController]) tries once and gives up,
 * and nothing anywhere says which titles are still bare. This page is that list, plus the three ways
 * out — upload your own image, take one the source search found, or pull the hotlink down before it
 * dies. Every route ends in the same place: a locally-stored WebP at [PosterBackfill::COVER_MAX_DIM],
 * so a cover an admin supplied behaves exactly like an imported one.
 *
 * Measured on prod 2026-08-19: 46 titles hold no poster at all (37 of them 24-hdx) and 226 still
 * hotlink, 211 of those from anifume and anime108 — two sources that have gone dark, which is why
 * [PosterSearch] looks across sites rather than only re-asking the origin.
 */
class CoverController extends Controller
{
    /** Titles per page — a poster grid, so a round number that fills 2/3/4/6-column rows evenly. */
    private const PER_PAGE = 24;

    /**
     * How many hotlinks one "ตรวจหาปกเสีย" pass verifies.
     *
     * Each check is a real image download against someone else's server, so this runs in the admin's
     * request and has to finish inside it. Kept resumable rather than large: a checked title either
     * heals (becomes local, leaves the candidate set) or gets flagged (excluded by the query), so
     * every pass permanently shrinks the work and the button can just be pressed again.
     */
    private const SCAN_BATCH = 25;

    public function index(Request $request): View
    {
        $bucket = in_array($request->query('bucket'), ['none', 'broken', 'hotlink'], true)
            ? $request->query('bucket')
            : 'all';
        $source = trim((string) $request->query('source', ''));
        $q = trim((string) $request->query('q', ''));

        $items = $this->needsCover(Content::withoutGlobalScopes(), $bucket)
            ->when($source !== '', fn ($b) => $b->where('source', $source))
            ->when($q !== '', fn ($b) => $b->where('title', 'like', '%'.$q.'%'))
            // Most-watched first: a bare cover costs the most on the title people actually open.
            ->orderByDesc('views')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.covers.index', [
            'items' => $items,
            'bucket' => $bucket,
            'source' => $source,
            'q' => $q,
            'counts' => $this->counts(),
            'sources' => $this->sourcesWithWork(),
        ]);
    }

    /** Admin: upload an image file as the cover (any format in, WebP out). */
    public function upload(Request $request, Content $content, PosterBackfill $backfill): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'string', 'max:10000000']]);   // ~7MB of base64

        $bin = ImageStore::decodeDataUrl($data['image']);
        if ($bin === null) {
            return response()->json(['ok' => false, 'error' => 'ไฟล์ไม่ใช่รูปภาพ หรือใหญ่เกินไป'], 422);
        }
        $path = ImageStore::putCover($bin, 'media/posters', (string) $content->id, $content->poster_path,
            PosterBackfill::COVER_MAX_DIM, PosterBackfill::COVER_QUALITY);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'อ่านรูปไม่ได้ ลองไฟล์อื่น'], 422);
        }
        $backfill->apply($content, $path);

        return $this->done($content);
    }

    /**
     * Admin: take a cover from a URL — a candidate picked out of the search results, or a link pasted
     * by hand. Scraped candidates carry the derivative WordPress cut for that grid, so the full-size
     * original is tried first (see [SiteSearchScraper::downloadOrder]).
     */
    public function fromUrl(Request $request, Content $content, PosterBackfill $backfill): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000', 'url']]);

        // The URL is not necessarily typed by the admin — the search candidates are read out of a
        // source site's HTML, so a hostile source could offer an internal address as a "cover".
        if (($why = SafeUrl::problem($data['url'])) !== null) {
            return response()->json(['ok' => false, 'error' => $why], 422);
        }

        $path = $backfill->storeFrom($content, SiteSearchScraper::downloadOrder($data['url']));
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'โหลดรูปจากลิงก์นี้ไม่ได้'], 422);
        }
        $backfill->apply($content, $path);

        return $this->done($content);
    }

    /** Admin: what the source sites offer for this title's name — candidates only, nothing is applied. */
    public function search(Content $content, PosterSearch $search): JsonResponse
    {
        $found = $search->find($content);

        return response()->json([
            'ok' => true,
            'candidates' => array_map(fn ($c) => $c->toArray(), $found),
            // The row can offer a one-click "ใช้เลย" when the names agree well enough to be safe.
            'auto' => PosterSearch::AUTO_SCORE,
        ]);
    }

    /**
     * Admin: try everything automatically for one title — re-ask the origin first (exact, by id), then
     * fall back to a by-name search and take the top hit ONLY if the titles agree closely. Anything
     * less certain is left for the admin to pick, because a wrong cover is worse than no cover.
     */
    public function auto(Content $content, PosterBackfill $backfill, PosterSearch $search): JsonResponse
    {
        if (($path = $backfill->recover($content)) !== null) {
            $backfill->apply($content, $path);

            return $this->done($content, 'ต้นทาง');
        }

        $best = $search->find($content, 3)[0] ?? null;
        if ($best === null || $best->score < PosterSearch::AUTO_SCORE) {
            return response()->json([
                'ok' => false,
                'error' => $best === null ? 'ไม่พบปกที่ไหนเลย' : 'เจอปกที่ใกล้เคียง แต่ชื่อไม่ตรงพอ — กดค้นหาเพื่อเลือกเอง',
            ], 422);
        }
        // Same reason as fromUrl: this URL came out of a source site's HTML, not out of our own data.
        if (SafeUrl::problem($best->image) !== null) {
            return response()->json(['ok' => false, 'error' => 'ลิงก์ปกที่เจอไม่ปลอดภัย — กดค้นหาเพื่อเลือกเอง'], 422);
        }

        $path = $backfill->storeFrom($content, SiteSearchScraper::downloadOrder($best->image));
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'เจอปกแล้วแต่โหลดรูปไม่สำเร็จ'], 422);
        }
        $backfill->apply($content, $path);

        return $this->done($content, $best->source);
    }

    /**
     * Admin: pull a still-working hotlinked cover down into our own storage.
     *
     * Not a repair — these covers load today. It stops them being served off someone else's server,
     * which is worth doing on its own: 24-hdx answers its covers with `Cache-Control: no-store`, so a
     * browser is forbidden to keep them and re-downloads every one on every page view.
     */
    public function localize(Content $content, PosterBackfill $backfill): JsonResponse
    {
        $path = $backfill->localize($content);
        if ($path === null) {
            return response()->json(['ok' => false, 'error' => 'ดึงปกมาเก็บไม่สำเร็จ (ต้นทางอาจตายแล้ว)'], 422);
        }
        $backfill->apply($content, $path);

        return $this->done($content);
    }

    /**
     * Admin: verify a batch of hotlinked covers the way a browser loads them, heal the dead ones, and
     * flag whatever can't be healed so it shows up in this list.
     *
     * This is what makes the "ปกเสีย" bucket fill up without waiting for a viewer to stumble onto a
     * broken card. Bounded per press — see [self::SCAN_BATCH].
     */
    public function scan(PosterBackfill $backfill): JsonResponse
    {
        @set_time_limit(0);

        $targets = Content::withoutGlobalScopes()
            ->where('poster_path', 'like', 'http%')
            ->whereNull('cover_missing_at')     // already-flagged ones are known — don't re-check them
            ->orderByDesc('views')->orderByDesc('id')
            ->limit(self::SCAN_BATCH)->get();

        $healed = $dead = 0;
        foreach ($targets as $content) {
            if ($backfill->urlAlive($content->poster_path)) {
                continue;
            }
            if (($path = $backfill->recover($content)) !== null) {
                $backfill->apply($content, $path);
                $healed++;

                continue;
            }
            // Query builder, not a model save — flagging a cover as unrecoverable must not bump
            // `updated_at`, which [SitemapController] publishes as the title's <lastmod>. Nothing
            // about the page changed; we only learned something about it. Same reason as in
            // [App\Http\Controllers\PosterHealController].
            DB::table('contents')->where('id', $content->id)->update(['cover_missing_at' => now()]);
            $dead++;
        }

        $counts = $this->counts();

        return response()->json([
            'ok' => true,
            'checked' => $targets->count(),
            'healed' => $healed,
            'dead' => $dead,
            'remaining' => $counts['hotlink'],
            'message' => $targets->isEmpty()
                ? 'ตรวจครบทุกปกที่เหลือแล้ว'
                : "ตรวจ {$targets->count()} เรื่อง · ซ่อมได้ {$healed} · เสียจริง {$dead} · เหลือรอตรวจ {$counts['hotlink']}",
        ]);
    }

    /**
     * The rows that need attention.
     *
     * - `none`    — no poster at all. The card has nothing to show but the branded gradient.
     * - `broken`  — a stored cover a browser could not load and nothing could re-fetch (flagged by the
     *               on-demand heal or by [self::scan]).
     * - `hotlink` — still served from the source's server. Not broken, but one deletion away from it,
     *               and slow meanwhile.
     */
    private function needsCover($builder, string $bucket)
    {
        return match ($bucket) {
            'none' => $builder->where(fn ($q) => $q->whereNull('poster_path')->orWhere('poster_path', '')),
            'broken' => $builder->whereNotNull('cover_missing_at'),
            'hotlink' => $builder->where('poster_path', 'like', 'http%')->whereNull('cover_missing_at'),
            // "all" deliberately leaves out the healthy hotlinks — they are a separate, much larger
            // housekeeping job, and mixing them in would bury the titles that actually look broken.
            default => $builder->where(fn ($q) => $q->whereNull('poster_path')
                ->orWhere('poster_path', '')
                ->orWhereNotNull('cover_missing_at')),
        };
    }

    /** @return array{none:int,broken:int,hotlink:int,all:int} */
    private function counts(): array
    {
        $base = fn () => Content::withoutGlobalScopes();

        return [
            'none' => $this->needsCover($base(), 'none')->count(),
            'broken' => $this->needsCover($base(), 'broken')->count(),
            'hotlink' => $this->needsCover($base(), 'hotlink')->count(),
            'all' => $this->needsCover($base(), 'all')->count(),
        ];
    }

    /**
     * Sources that actually have work in this list, with their counts — the filter only offers what
     * would return rows, so it never sends the admin to an empty page.
     *
     * @return array<string,int>
     */
    private function sourcesWithWork(): array
    {
        return $this->needsCover(Content::withoutGlobalScopes(), 'all')
            ->selectRaw('source, count(*) as n')
            ->whereNotNull('source')
            ->groupBy('source')->orderByDesc('n')
            ->pluck('n', 'source')->all();
    }

    /** The shape every "this title now has a cover" response takes. */
    private function done(Content $content, ?string $via = null): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'url' => $content->fresh()->poster_url,
            'via' => $via,
        ]);
    }
}
