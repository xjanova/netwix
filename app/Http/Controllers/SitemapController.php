<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Genre;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * XML sitemaps. `/sitemap.xml` is now a sitemap INDEX pointing at typed children:
 *   - sitemap-pages.xml   marketing/info pages
 *   - sitemap-titles.xml  every public (published, non-suspended, non-adult) title — with poster
 *                         images for Google Images and a lastmod so re-crawls are cheap
 *   - sitemap-genres.xml  every genre hub that has public content
 *
 * The catalog used to be omitted entirely (it sat behind auth); the public title/genre surface is
 * what makes these crawlable. Playback (/watch) stays gated and out of the sitemap.
 */
class SitemapController extends Controller
{
    private const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

    /** `/sitemap.xml` — the index of child sitemaps. */
    public function index(): Response
    {
        $children = [
            route('sitemap.pages'),
            route('sitemap.titles'),
            route('sitemap.genres'),
        ];

        $xml = self::XML_HEADER.'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($children as $loc) {
            $xml .= '  <sitemap><loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'
                .'<lastmod>'.now()->toDateString().'</lastmod></sitemap>'."\n";
        }
        $xml .= '</sitemapindex>'."\n";

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

    /** Every publicly-visible title, with a poster image + lastmod. */
    public function titles(): Response
    {
        $xml = self::XML_HEADER
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            .'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        Content::publicListing()
            ->orderByDesc('updated_at')
            ->select(['id', 'slug', 'title', 'poster_path', 'updated_at'])
            ->chunk(500, function ($chunk) use (&$xml) {
                foreach ($chunk as $c) {
                    $loc = htmlspecialchars(route('title.show', $c), ENT_XML1);
                    $xml .= '  <url><loc>'.$loc.'</loc>';
                    $xml .= '<lastmod>'.optional($c->updated_at)->toDateString().'</lastmod>';
                    $xml .= '<changefreq>weekly</changefreq><priority>0.8</priority>';
                    if ($img = $this->absoluteImage($c->poster_url)) {
                        $xml .= '<image:image><image:loc>'.htmlspecialchars($img, ENT_XML1).'</image:loc>'
                            .'<image:title>'.htmlspecialchars($c->title, ENT_XML1).'</image:title></image:image>';
                    }
                    $xml .= '</url>'."\n";
                }
            });

        $xml .= '</urlset>'."\n";

        return $this->xml($xml);
    }

    /** Genre hubs that actually have public content. */
    public function genres(): Response
    {
        $genres = Genre::whereHas('contents', fn ($q) => $q->publicListing())->get();

        $xml = self::XML_HEADER.'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($genres as $g) {
            $xml .= '  <url>'
                .'<loc>'.htmlspecialchars(route('browse.genre', $g), ENT_XML1).'</loc>'
                .'<changefreq>daily</changefreq><priority>0.7</priority>'
                .'</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return $this->xml($xml);
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
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
