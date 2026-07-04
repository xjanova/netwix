<?php

namespace App\Http\Middleware;

use App\Models\PageView;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one row per successful, human, HTML GET page view for the admin SEO/traffic
 * dashboard. Deliberately narrow — assets, streams, API/JSON, admin, redirects, errors and
 * crawlers are all skipped — so the count reflects real visitors, and never breaks a render.
 */
class TrackPageView
{
    /** Path prefixes that are never counted (infra, admin, media, bridges). */
    private const SKIP = ['admin', 'api', 'stream', 'storage', 'build', 'download', 'mauth', 'sitemap.xml', 'up', 'auth'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->shouldLog($request, $response)) {
                PageView::create([
                    'path' => Str::limit(ltrim($request->path(), '/'), 180, ''),
                    'is_member' => auth()->check(),
                    'created_at' => now(),
                ]);

                // Opportunistic prune keeps the table bounded without needing a cron entry.
                if (random_int(1, 300) === 1) {
                    PageView::where('created_at', '<', now()->subDays(90))->limit(10000)->delete();
                }
            }
        } catch (\Throwable $e) {
            // Analytics is best-effort — a logging failure must never affect the page.
        }

        return $response;
    }

    private function shouldLog(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return false;
        }
        // Only pages actually delivered (200s) — not redirects (302 to login/profile) or errors.
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
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

        // Human visitors only — search-engine crawlers are excluded from the traffic count.
        $ua = (string) $request->userAgent();

        return $ua !== '' && ! preg_match('~bot|crawl|spider|slurp|bing|google|yandex|duckduck|baidu|facebookexternalhit|headless|preview|monitor~i', $ua);
    }
}
