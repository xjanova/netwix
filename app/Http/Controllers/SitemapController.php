<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Public XML sitemap. Only the crawlable marketing pages are listed — the
     * catalog itself (browse/title/watch) sits behind auth, so it isn't indexed.
     */
    public function __invoke(): Response
    {
        $pages = [
            ['home', '1.0', 'daily'],
            ['download', '0.7', 'weekly'],
            ['register', '0.6', 'monthly'],
            ['help', '0.5', 'monthly'],
            ['login', '0.4', 'monthly'],
            ['terms', '0.3', 'yearly'],
            ['privacy', '0.3', 'yearly'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($pages as [$name, $priority, $freq]) {
            $xml .= '  <url>'
                .'<loc>'.htmlspecialchars(route($name), ENT_XML1).'</loc>'
                .'<changefreq>'.$freq.'</changefreq>'
                .'<priority>'.$priority.'</priority>'
                .'</url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
