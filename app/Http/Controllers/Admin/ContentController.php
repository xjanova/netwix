<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Episode;
use App\Models\Genre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ContentController extends Controller
{
    public function index(Request $request): View
    {
        $type = $request->query('type');
        $q = trim((string) $request->query('q', ''));
        $genre = $request->query('genre');
        $maturity = $request->query('maturity');
        $minRating = $request->query('min_rating');
        $sort = $request->query('sort', 'latest');

        $contents = Content::query()
            ->when($type && $type !== 'all', fn ($w) => $w->where('type', $type))
            ->when($q !== '', fn ($w) => $w->where('title', 'like', "%{$q}%"))
            ->when($genre, fn ($w) => $w->whereHas('genres', fn ($g) => $g->where('genres.id', $genre)))
            ->when($maturity, fn ($w) => $w->where('maturity', $maturity))
            ->when($minRating !== null && $minRating !== '', fn ($w) => $w->where('rating', '>=', (float) $minRating))
            ->with('genres')
            ->withCount(['episodes', 'likedBy', 'comments'])
            ->withAvg('ratings', 'stars')
            ->when($sort === 'views', fn ($w) => $w->orderByDesc('views'))
            ->when($sort === 'rating', fn ($w) => $w->orderByDesc('rating'))
            ->when($sort === 'likes', fn ($w) => $w->orderByDesc('liked_by_count'))
            ->when(! in_array($sort, ['views', 'rating', 'likes'], true), fn ($w) => $w->latest())
            ->paginate(12)
            ->withQueryString();

        return view('admin.contents.index', [
            'contents' => $contents,
            'type' => $type ?: 'all',
            'q' => $q,
            'genre' => $genre,
            'maturity' => $maturity,
            'minRating' => $minRating,
            'sort' => $sort,
            'genres' => Genre::orderBy('sort')->get(),
            'maturities' => Content::query()->whereNotNull('maturity')->where('maturity', '!=', '')
                ->distinct()->orderBy('maturity')->pluck('maturity'),
            'counts' => [
                'all' => Content::count(),
                'series' => Content::where('type', 'series')->count(),
                'movie' => Content::where('type', 'movie')->count(),
                'vertical' => Content::where('type', 'vertical')->count(),
            ],
            'dupIds' => self::duplicateContentIds(),
        ]);
    }

    /**
     * Admin: "ตรวจแล้ว ลิงก์ปกติ" — tick to stop this title being flagged for review (and clear its
     * current flag); untick to allow it back on the review list if playback fails again later.
     */
    public function toggleReviewIgnore(Request $request, Content $content): JsonResponse
    {
        $ignored = $request->boolean('ignored');
        $content->update([
            'review_ignored' => $ignored,
            'review_flagged_at' => $ignored ? null : $content->review_flagged_at,
        ]);

        return response()->json(['ok' => true, 'ignored' => $ignored]);
    }

    /**
     * Ids of contents whose normalised title collides with at least one other content — surfaced as
     * a "ซ้ำ?" flag for admin review. Full-table scan, cached briefly (admin-only, low traffic).
     *
     * @return int[]
     */
    public static function duplicateContentIds(): array
    {
        return Cache::remember('admin:dup_content_ids', now()->addSeconds(30), function () {
            $byKey = [];
            Content::query()->select('id', 'title', 'slug')->get()->each(function ($c) use (&$byKey) {
                $key = Content::dedupeKey($c->title ?: $c->slug);
                if ($key !== '') {
                    $byKey[$key][] = $c->id;
                }
            });

            return collect($byKey)->filter(fn ($ids) => count($ids) > 1)->flatten()->all();
        });
    }

    public function create(): View
    {
        return view('admin.contents.form', [
            'content' => new Content(['type' => 'series', 'maturity' => '13+', 'match_score' => 96, 'rating' => 8.5, 'is_published' => true]),
            'genres' => Genre::orderBy('sort')->get(),
            'selectedGenres' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $content = Content::create($this->payload($data));
        $this->syncGenres($content, $data);

        return redirect()->route('admin.contents.edit', $content)->with('status', 'สร้างคอนเทนต์เรียบร้อยแล้ว');
    }

    public function edit(Content $content): View
    {
        $content->load(['genres', 'episodes' => fn ($q) => $q->orderBy('season_id')->orderBy('number')]);

        return view('admin.contents.form', [
            'content' => $content,
            'genres' => Genre::orderBy('sort')->get(),
            'selectedGenres' => $content->genres->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, Content $content): RedirectResponse
    {
        $data = $this->validated($request, $content);
        $content->update($this->payload($data));
        $this->syncGenres($content, $data);

        return redirect()->route('admin.contents.edit', $content)->with('status', 'บันทึกการเปลี่ยนแปลงแล้ว');
    }

    public function destroy(Content $content): RedirectResponse
    {
        $content->delete();

        return redirect()->route('admin.contents.index')->with('status', 'ลบคอนเทนต์แล้ว');
    }

    /** Clear captured episode covers for one title — they re-grab a fresh frame on the next watch. */
    public function resetThumbs(Content $content): RedirectResponse
    {
        $n = $this->clearThumbs($content->episodes()->whereNotNull('thumbnail_path')->get());

        return back()->with('status', "รีเซ็ตปกตอนของ \"{$content->title}\" แล้ว ({$n} ตอน) — จะจับภาพใหม่เมื่อมีคนดู");
    }

    /** Same, for the whole catalogue at once. */
    public function resetAllThumbs(): RedirectResponse
    {
        $n = $this->clearThumbs(Episode::whereNotNull('thumbnail_path')->get());

        return back()->with('status', "รีเซ็ตปกตอนทั้งหมดแล้ว ({$n} ตอน) — จะจับภาพใหม่เมื่อมีคนดู");
    }

    /** @param  \Illuminate\Support\Collection<int,Episode>  $episodes */
    private function clearThumbs($episodes): int
    {
        foreach ($episodes as $ep) {
            // only delete frames we captured ourselves — never an externally-set http thumbnail
            if ($ep->thumbnail_path && str_starts_with($ep->thumbnail_path, 'media/thumbs/')) {
                Storage::disk('public')->delete($ep->thumbnail_path);
            }
            $ep->update(['thumbnail_path' => null]);
        }

        return $episodes->count();
    }

    // ---- helpers -------------------------------------------------------

    private function validated(Request $request, ?Content $content = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:contents,slug'.($content ? ','.$content->id : '')],
            'type' => ['required', 'in:series,movie,vertical'],
            'synopsis' => ['nullable', 'string'],
            'year' => ['nullable', 'integer', 'between:1950,2100'],
            'maturity' => ['required', 'string', 'max:8'],
            'match_score' => ['required', 'integer', 'between:0,100'],
            'rating' => ['required', 'numeric', 'between:0,10'],
            'is_original' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'is_vip' => ['sometimes', 'boolean'],
            'vip_price_gold' => ['nullable', 'integer', 'between:0,1000000'],
            'poster_path' => ['nullable', 'string', 'max:2048'],
            'backdrop_path' => ['nullable', 'string', 'max:2048'],
            'trailer_youtube_id' => ['nullable', 'string', 'max:64'],
            'video_url' => ['nullable', 'string', 'max:2048'],
            'duration_minutes' => ['nullable', 'integer', 'between:0,1000'],
            'genres' => ['array'],
            'genres.*' => ['integer', 'exists:genres,id'],
            'primary_genre' => ['nullable', 'integer'],
        ], [
            'title.required' => 'กรุณากรอกชื่อเรื่อง',
        ]);
    }

    private function payload(array $data): array
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']) ?: Str::random(8);
        foreach (['is_original', 'is_featured', 'is_published', 'is_vip'] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }
        unset($data['genres'], $data['primary_genre']);

        return $data;
    }

    private function syncGenres(Content $content, array $data): void
    {
        $ids = $data['genres'] ?? [];
        $primary = $data['primary_genre'] ?? ($ids[0] ?? null);

        $content->genres()->sync(collect($ids)->mapWithKeys(fn ($id) => [
            $id => ['is_primary' => (int) $id === (int) $primary],
        ])->all());
    }
}
