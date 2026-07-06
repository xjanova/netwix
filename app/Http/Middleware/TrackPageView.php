<?php

namespace App\Http\Middleware;

use App\Models\CrawlerHit;
use App\Models\PageView;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one row per successful HTML GET of a public page. Human visitors go to page_views (with a
 * coarse traffic-source bucket); search-engine / AI crawlers go to crawler_hits so the admin can see
 * whether Google/Bing/GPTBot are actually finding the catalog. Assets, streams, API/JSON, admin,
 * redirects and errors are all skipped, and the whole thing is best-effort — it never breaks a render.
 *
 * PDPA: stores path + a bot label or source bucket only. No IP, no raw referer URL, no user id.
 */
class TrackPageView
{
    /** Path prefixes that are never counted (infra, admin, media, bridges). */
    private const SKIP = ['admin', 'api', 'stream', 'storage', 'build', 'download', 'mauth', 'sitemap.xml', 'up', 'auth'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->isCountablePage($request, $response)) {
                $path = Str::limit(ltrim($request->path(), '/'), 180, '');
                $ua = (string) $request->userAgent();
                $bot = $this->botName($ua);

                if ($bot !== null) {
                    CrawlerHit::create(['bot' => $bot, 'path' => $path, 'created_at' => now()]);
                    $this->prune();
                } elseif ($ua !== '' && ! $request->ajax() && ! $request->wantsJson()) {
                    PageView::create([
                        'path' => $path,
                        'is_member' => auth()->check(),
                        'source' => $this->source($request),
                        'created_at' => now(),
                    ]);
                    $this->prune();
                }
            }
        } catch (\Throwable $e) {
            // Analytics is best-effort — a logging failure must never affect the page.
        }

        return $response;
    }

    /** GET, 200-ish, real HTML, not an infra/skip path. (Human vs bot is decided by the caller.) */
    private function isCountablePage(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;   // redirects (to login/profile) + errors don't count
        }
        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;   // assets, streams, JSON, file downloads
        }

        $path = ltrim($request->path(), '/');
        foreach (self::SKIP as $p) {
            if ($path === $p || str_starts_with($path, $p.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical crawler label for a user-agent, or null for a human. Also used to keep bots out of the
     * human traffic count. Order matters — check specific bots before the generic catch-all.
     */
    private function botName(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }

        $map = [
            'Googlebot' => 'Googlebot', 'Google-InspectionTool' => 'Googlebot',
            'AdsBot-Google' => 'Googlebot', 'Storebot-Google' => 'Googlebot', 'GoogleOther' => 'Googlebot',
            'Google-Extended' => 'Google-Extended',
            'bingbot' => 'Bingbot', 'BingPreview' => 'Bingbot', 'adidxbot' => 'Bingbot',
            'GPTBot' => 'GPTBot', 'OAI-SearchBot' => 'OAI-SearchBot', 'ChatGPT-User' => 'ChatGPT',
            'PerplexityBot' => 'PerplexityBot', 'Perplexity-User' => 'PerplexityBot',
            'ClaudeBot' => 'ClaudeBot', 'Claude-Web' => 'ClaudeBot', 'anthropic-ai' => 'ClaudeBot',
            'Amazonbot' => 'Amazonbot', 'Applebot' => 'Applebot',
            'YandexBot' => 'YandexBot', 'DuckDuckBot' => 'DuckDuckBot', 'Baiduspider' => 'Baiduspider',
            'facebookexternalhit' => 'Facebook', 'meta-externalagent' => 'Facebook',
            'Twitterbot' => 'Twitterbot', 'LineBot' => 'LINE', 'Line/' => 'LINE',
        ];

        foreach ($map as $needle => $label) {
            if (stripos($ua, $needle) !== false) {
                return $label;
            }
        }

        // Generic fallback so unknown crawlers still stay out of the human count.
        if (preg_match('~bot|crawl|spider|slurp|headless|monitor|preview|scrapy|python-requests|curl|wget~i', $ua)) {
            return 'Other bot';
        }

        return null;
    }

    /**
     * Coarse traffic-source bucket from the Referer host — bucket string ONLY, never the raw URL.
     * Internal referers return null (in-site navigation isn't a "source").
     */
    private function source(Request $request): ?string
    {
        $ref = (string) $request->headers->get('referer', '');
        if ($ref === '') {
            return 'direct';
        }

        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($host === '') {
            return 'other';
        }
        if (str_contains($host, 'netwix')) {
            return null;   // internal navigation — don't attribute a source
        }

        $buckets = [
            'google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
            'facebook' => 'facebook', 'fb.' => 'facebook', 'messenger' => 'facebook',
            'line.me' => 'line', 'liff' => 'line',
            't.co' => 'twitter', 'twitter' => 'twitter', 'x.com' => 'twitter',
            'youtube' => 'youtube', 'tiktok' => 'tiktok', 'instagram' => 'instagram',
            'reddit' => 'reddit', 'pinterest' => 'pinterest', 'telegram' => 'telegram', 't.me' => 'telegram',
        ];
        foreach ($buckets as $needle => $label) {
            if (str_contains($host, $needle)) {
                return $label;
            }
        }

        return 'other';
    }

    /** Opportunistic prune keeps both tables bounded without a cron entry. */
    private function prune(): void
    {
        if (random_int(1, 300) === 1) {
            PageView::where('created_at', '<', now()->subDays(90))->limit(10000)->delete();
            CrawlerHit::where('created_at', '<', now()->subDays(90))->limit(10000)->delete();
        }
    }
}
