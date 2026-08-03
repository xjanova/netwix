<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Genre;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * XML sitemaps. `/sitemap.xml` is a sitemap INDEX pointing at typed children:
 *   - sitemap-pages.xml          marketing/info pages
 *   - sitemap-genres.xml         every genre hub that has public content
 *   - sitemap-titles-{n}.xml     every public title, in chunks — with poster images for Google
 *                                Images and a lastmod so re-crawls are cheap
 *
 * The catalog used to be omitted entirely (it sat behind auth); the public title/genre surface is
 * what makes these crawlable. Playback (/watch) stays gated and out of the sitemap.
 *
 * Chunked + cached since 2026-08-03: the titles sitemap had grown to 18,874 URLs in ONE ~1 MB file
 * rebuilt from a live DB query on every request, and AhrefsBot alone re-fetches it hourly. Chunks
 * are ordered by id so a title that gets touched cannot shuffle across file boundaries.
 */
class SitemapController extends Controller
{
    private const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

    /** URLs per titles chunk (the spec allows 50k, but smaller files re-fetch and diff faster). */
    private const CHUNK = 5000;

    /** How long a built sitemap stays cached. */
    private const TTL_HOURS = 6;

    /** `/sitemap.xml` — the index of child sitemaps. */
    public function index(): Response
    {
        $xml = Cache::remember('sitemap:index', now()->addHours(self::TTL_HOURS), function () {
            $today = now()->toDateString();
            // One lastmod for every titles chunk: the catalog is touched by imports constantly, so
            // per-chunk precision would cost a query each and change almost as often anyway. When
            // it moves we WANT Google to re-pull all the chunks.
            $maxUpdated = Content::publicListing()->max('updated_at');   // raw scalar, not a Carbon
            $titlesLastmod = $maxUpdated ? Str::substr((string) $maxUpdated, 0, 10) : $today;

            $children = [
                [route('sitemap.pages'), $today],
                [route('sitemap.genres'), $today],
            ];

            for ($page = 1, $last = $this->titlePageCount(); $page <= $last; $page++) {
                $children[] = [route('sitemap.titles.page', $page), $titlesLastmod];
            }

            $out = self::XML_HEADER.'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($children as [$loc, $lastmod]) {
                $out .= '  <sitemap><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'
                    .'<lastmod>'.$lastmod.'</lastmod></sitemap>'."\n";
            }

            return $out.'</sitemapindex>'."\n";
        });

        return $this->xml($xml);
    }

    /** Static marketing + info pages. */
    public function pages(): Response
    {
        $pages = [
            ['home', '1.0', 'daily'],
            // Category hubs — high-value head-term landing pages.
            ['browse.series', '0.9', 'daily'],
            ['browse.movies', '0.9', 'daily'],
            ['browse.anime', '0.9', 'daily'],
            ['browse.vertical', '0.9', 'daily'],
            ['download', '0.7', 'weekly'],
            ['help', '0.5', 'monthly'],
            ['register', '0.6', 'monthly'],
            ['login', '0.4', 'monthly'],
            ['terms', '0.3', 'yearly'],
            ['privacy', '0.3', 'yearly'],
        ];

        $xml = self::XML_HEADER.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($pages as [$name, $priority, $freq]) {
            $xml .= '  <url>'
                .'<loc>'.htmlspecialchars(route($name), ENT_XML1).'</loc>'
                .'<changefreq>'.$freq.'</changefreq>'
                .'<priority>'.$priority.'</priority>'
                .'</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return $this->xml($xml);
    }

    /** One chunk of publicly-visible titles, with a poster image + lastmod each. */
    public function titles(int $page = 1): Response
    {
        abort_if($page < 1 || $page > $this->titlePageCount(), 404);

        $xml = Cache::remember('sitemap:titles:'.$page, now()->addHours(self::TTL_HOURS), function () use ($page) {
            $out = self::XML_HEADER
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
                .'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

            // cursor() (not chunk()) — chunk() rewrites limit/offset and would silently ignore the
            // window we just asked for, handing every chunk file the same first 5,000 titles.
            $rows = Content::publicListing()
                ->orderBy('id')
                ->skip(($page - 1) * self::CHUNK)
                ->take(self::CHUNK)
                ->select(['id', 'slug', 'title', 'poster_path', 'updated_at'])
                ->cursor();

            foreach ($rows as $c) {
                $out .= '  <url><loc>'.htmlspecialchars(route('title.show', $c), ENT_XML1).'</loc>';
                $out .= '<lastmod>'.optional($c->updated_at)->toDateString().'</lastmod>';
                $out .= '<changefreq>weekly</changefreq><priority>0.8</priority>';
                if ($img = $this->absoluteImage($c->poster_url)) {
                    $out .= '<image:image><image:loc>'.htmlspecialchars($img, ENT_XML1).'</image:loc>'
                        .'<image:title>'.htmlspecialchars($c->title, ENT_XML1).'</image:title></image:image>';
                }
                $out .= '</url>'."\n";
            }

            return $out.'</urlset>'."\n";
        });

        return $this->xml($xml);
    }

    /** Genre hubs that actually have public content. */
    public function genres(): Response
    {
        $xml = Cache::remember('sitemap:genres', now()->addHours(self::TTL_HOURS), function () {
            $genres = Genre::whereHas('contents', fn ($q) => $q->publicListing())->get();

            $out = self::XML_HEADER.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            foreach ($genres as $g) {
                $out .= '  <url>'
                    .'<loc>'.htmlspecialchars(route('browse.genre', $g), ENT_XML1).'</loc>'
                    .'<changefreq>daily</changefreq><priority>0.7</priority>'
                    .'</url>'."\n";
            }

            return $out.'</urlset>'."\n";
        });

        return $this->xml($xml);
    }

    /** How many `sitemap-titles-{n}.xml` files the catalog currently needs (at least one). */
    private function titlePageCount(): int
    {
        $total = Cache::remember('sitemap:titles:count', now()->addHours(self::TTL_HOURS),
            fn () => Content::publicListing()->count());

        return max(1, (int) ceil($total / self::CHUNK));
    }

    /** Absolutise a poster URL for the sitemap (Google requires absolute image locs). */
    private function absoluteImage(?string $poster): ?string
    {
        if (! $poster) {
            return null;
        }

        return Str::startsWith($poster, 'http') ? $poster : url($poster);
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            // The files are rebuilt at most every TTL_HOURS, so let the edge hold them too —
            // Ahrefs/Bing/Claude re-fetch these far more often than the catalog changes.
            'Cache-Control' => 'public, max-age='.(self::TTL_HOURS * 3600),
        ]);
    }
}
