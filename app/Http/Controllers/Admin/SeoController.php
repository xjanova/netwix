<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\CrawlerHit;
use App\Models\Genre;
use App\Models\PageView;
use App\Models\SearchQuery;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin SEO & traffic dashboard: keyword defaults, SEO health, self-logged human traffic (PageView),
 * crawler activity (CrawlerHit — is Google/Bing/GPTBot actually finding us), traffic sources, and the
 * on-site search "content gap" report (SearchQuery). All charts are CSS bars (no JS lib → CSP-safe).
 */
class SeoController extends Controller
{
    public function index(): View
    {
        $since = Carbon::today()->subDays(29);

        $keywords = [
            'seo_keywords' => (string) Setting::get('seo_keywords', ''),
            'seo_kw_series' => (string) Setting::get('seo_kw_series', ''),
            'seo_kw_movie' => (string) Setting::get('seo_kw_movie', ''),
            'seo_kw_vertical' => (string) Setting::get('seo_kw_vertical', ''),
        ];

        // ---- Human traffic (last 30 days) ----
        $rows = PageView::where('created_at', '>=', $since)->get(['path', 'is_member', 'source', 'created_at']);

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

        // ---- Traffic sources (where visitors came FROM) ----
        $bySource = $rows->whereNotNull('source')->groupBy('source')->map->count()->sortDesc();
        $srcMax = max(1, $bySource->max() ?: 1);
        $sources = $bySource->map(fn ($count, $s) => [
            'label' => $this->sourceLabel((string) $s),
            'value' => $count,
            'pct' => round($count / $srcMax * 100).'%',
        ])->values();

        // ---- Crawler activity (is Google/Bing/AI finding the pages?) ----
        $crawler = CrawlerHit::where('created_at', '>=', $since)->get(['bot', 'path', 'created_at']);
        $byBot = $crawler->groupBy('bot');
        $botMax = max(1, $byBot->map->count()->max() ?: 1);
        $crawlerBots = $byBot->map(fn ($g, $bot) => [
            'label' => $bot,
            'value' => $g->count(),
            'pct' => round($g->count() / $botMax * 100).'%',
            'ago' => optional($g->max('created_at'))->diffForHumans() ?? '-',
        ])->sortByDesc('value')->values();

        $topCrawled = $crawler->groupBy('path')->map->count()->sortDesc()->take(12)
            ->map(fn ($count, $path) => ['label' => $this->label((string) $path), 'value' => $count])->values();
        $crawledMax = max(1, $topCrawled->max('value') ?: 1);
        $topCrawled = $topCrawled->map(fn ($p) => $p + ['pct' => round($p['value'] / $crawledMax * 100).'%']);

        $googleHits = $crawler->filter(fn ($h) => str_contains((string) $h->bot, 'Google'))->count();
        $crawlerKpis = [
            ['label' => 'บอตเก็บหน้า (30 วัน)', 'value' => number_format($crawler->count())],
            ['label' => 'Googlebot เก็บ (30 วัน)', 'value' => number_format($googleHits)],
            ['label' => 'ชนิดบอตที่เจอ', 'value' => number_format($byBot->count())],
            ['label' => 'Googlebot ล่าสุด', 'value' => optional($crawler->filter(fn ($h) => str_contains((string) $h->bot, 'Google'))->max('created_at'))->diffForHumans() ?? 'ยังไม่พบ'],
        ];

        // ---- On-site search: content gap ----
        $topSearches = SearchQuery::where('created_at', '>=', $since)
            ->select('term', DB::raw('count(*) as hits'), DB::raw('max(results) as results'))
            ->groupBy('term')->orderByDesc('hits')->limit(15)->get();
        $gapSearches = SearchQuery::where('created_at', '>=', $since)->where('results', 0)
            ->select('term', DB::raw('count(*) as hits'))
            ->groupBy('term')->orderByDesc('hits')->limit(15)->get();

        // ---- SEO health ----
        $public = Content::publicListing();
        $health = [
            'publicTitles' => (clone $public)->count(),
            'genresPublic' => Genre::whereHas('contents', fn ($q) => $q->publicListing())->count(),
            'genresTotal' => Genre::count(),
            'missingSynopsis' => (clone $public)->where(fn ($q) => $q->whereNull('synopsis')->orWhere('synopsis', ''))->count(),
            'missingPoster' => (clone $public)->whereNull('poster_path')->count(),
            'missingGenre' => (clone $public)->whereDoesntHave('genres')->count(),
            'richReady' => (clone $public)->whereNotNull('poster_path')
                ->where(fn ($q) => $q->whereNotNull('synopsis')->where('synopsis', '!=', ''))
                ->whereHas('genres')->count(),
        ];
        $health['indexable'] = $health['publicTitles'] + $health['genresPublic'] + 7; // titles + genres + marketing pages

        return view('admin.seo.index', compact(
            'keywords', 'daily', 'topPages', 'kpis', 'health',
            'sources', 'crawlerBots', 'topCrawled', 'crawlerKpis', 'topSearches', 'gapSearches'
        ));
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

    /** Friendly label for a traffic-source bucket. */
    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'direct' => 'เข้าตรง (พิมพ์ URL / บุ๊กมาร์ก)',
            'google' => 'Google ค้นหา',
            'bing' => 'Bing',
            'yahoo' => 'Yahoo',
            'duckduckgo' => 'DuckDuckGo',
            'facebook' => 'Facebook',
            'line' => 'LINE',
            'twitter' => 'X / Twitter',
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'instagram' => 'Instagram',
            'reddit' => 'Reddit',
            'telegram' => 'Telegram',
            'pinterest' => 'Pinterest',
            default => ucfirst($source),
        };
    }
}
