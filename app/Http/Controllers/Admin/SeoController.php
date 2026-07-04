<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Genre;
use App\Models\PageView;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Admin SEO & traffic dashboard: edit the site-wide + per-type keyword defaults (stored in
 * Setting, consumed by partials/head + Content::seo_keywords), see SEO health at a glance, and
 * read the self-logged human traffic (PageView) as per-day and per-page charts.
 */
class SeoController extends Controller
{
    public function index(): View
    {
        $keywords = [
            'seo_keywords' => (string) Setting::get('seo_keywords', ''),
            'seo_kw_series' => (string) Setting::get('seo_kw_series', ''),
            'seo_kw_movie' => (string) Setting::get('seo_kw_movie', ''),
            'seo_kw_vertical' => (string) Setting::get('seo_kw_vertical', ''),
        ];

        // ---- Traffic (last 30 days of self-logged human page views) ----
        $since = Carbon::today()->subDays(29);
        $rows = PageView::where('created_at', '>=', $since)->get(['path', 'is_member', 'created_at']);

        $byDay = $rows->groupBy(fn ($r) => optional($r->created_at)->format('Y-m-d'));
        $daily = collect(range(29, 0))->map(function ($n) use ($byDay) {
            $d = Carbon::today()->subDays($n);

            return ['label' => $d->format('j/n'), 'value' => $byDay->get($d->format('Y-m-d'), collect())->count()];
        });
        $dayMax = max(1, $daily->max('value'));
        $daily = $daily->map(fn ($d) => $d + ['height' => round($d['value'] / $dayMax * 100).'%']);

        $topPages = $rows->groupBy('path')->map->count()->sortDesc()->take(15)
            ->map(fn ($count, $path) => ['label' => $this->label((string) $path), 'value' => $count])->values();
        $topMax = max(1, $topPages->max('value') ?: 1);
        $topPages = $topPages->map(fn ($p) => $p + ['pct' => round($p['value'] / $topMax * 100).'%']);

        $total = $rows->count();
        $members = $rows->where('is_member', true)->count();
        $kpis = [
            ['label' => 'เข้าชมวันนี้', 'value' => number_format($rows->where('created_at', '>=', Carbon::today())->count())],
            ['label' => 'เข้าชม 7 วัน', 'value' => number_format($rows->where('created_at', '>=', Carbon::today()->subDays(6))->count())],
            ['label' => 'เข้าชม 30 วัน', 'value' => number_format($total)],
            ['label' => 'สัดส่วนสมาชิก', 'value' => ($total ? round($members / $total * 100) : 0).'%'],
        ];

        // ---- SEO health ----
        $published = Content::where('is_published', true);
        $health = [
            'titles' => (clone $published)->count(),
            'genres' => Genre::count(),
            'missingSynopsis' => (clone $published)->where(fn ($q) => $q->whereNull('synopsis')->orWhere('synopsis', ''))->count(),
            'missingPoster' => (clone $published)->whereNull('poster_path')->count(),
        ];
        $health['indexable'] = $health['titles'] + $health['genres'] + 5;   // titles + genres + marketing pages

        return view('admin.seo.index', compact('keywords', 'daily', 'topPages', 'kpis', 'health'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'seo_keywords' => ['nullable', 'string', 'max:2000'],
            'seo_kw_series' => ['nullable', 'string', 'max:2000'],
            'seo_kw_movie' => ['nullable', 'string', 'max:2000'],
            'seo_kw_vertical' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach (['seo_keywords', 'seo_kw_series', 'seo_kw_movie', 'seo_kw_vertical'] as $key) {
            $val = trim((string) ($data[$key] ?? ''));
            Setting::write($key, $val !== '' ? $val : null);
        }

        return back()->with('status', 'บันทึกคีย์เวิร์ด SEO แล้ว');
    }

    /** Friendly Thai label for a logged path (resolves title/genre slugs to their names). */
    private function label(string $path): string
    {
        return match (true) {
            $path === '' || $path === '/' => 'หน้าแรก (Landing)',
            $path === 'browse' => 'หน้าแรกสมาชิก',
            $path === 'vertical' => 'ซีรีส์แนวตั้ง',
            $path === 'series' => 'ซีรี่ส์',
            $path === 'movies' => 'ภาพยนตร์',
            $path === 'anime' => 'อนิเมะ',
            $path === 'search' => 'ค้นหา',
            str_starts_with($path, 'genre/') => 'หมวด: '.(Genre::where('slug', substr($path, 6))->value('name') ?? substr($path, 6)),
            str_starts_with($path, 'title/') => 'เรื่อง: '.(Content::where('slug', substr($path, 6))->value('title') ?? substr($path, 6)),
            str_starts_with($path, 'watch/') => 'ดู: '.substr($path, 6),
            default => '/'.$path,
        };
    }
}
