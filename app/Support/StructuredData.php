<?php

namespace App\Support;

use App\Models\Content;
use Illuminate\Support\Str;

/**
 * Builds schema.org JSON-LD for the public title page — the machine-readable description Google's
 * rich results + AI answer engines (SGE / Gemini / ChatGPT / Perplexity) read to understand each
 * title. Most legacy Thai streaming sites ship none of this, so it's a cheap way to out-rank them.
 *
 * Emits a @graph of: BreadcrumbList → Movie|TVSeries (with genre, rating, episodes) → optional
 * VideoObject for the trailer. Everything degrades gracefully when a field is missing.
 */
class StructuredData
{
    /** @return array<string,mixed> the full JSON-LD document for a title page. */
    public static function forTitle(Content $content): array
    {
        $url = route('title.show', $content);

        $graph = array_values(array_filter([
            self::breadcrumb($content, $url),
            self::work($content, $url),
            self::trailer($content),
        ]));

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /** The main Movie / TVSeries node. */
    private static function work(Content $c, string $url): array
    {
        $isSeries = $c->type === 'series' || $c->type === 'vertical';

        $node = [
            '@type' => $isSeries ? 'TVSeries' : 'Movie',
            '@id' => $url.'#work',
            'url' => $url,
            'name' => $c->title,
            'inLanguage' => 'th-TH',
            'isPartOf' => ['@id' => url('/').'#website'],
            'publisher' => ['@id' => url('/').'#organization'],
        ];

        if ($c->synopsis) {
            $node['description'] = $c->synopsis;
        }
        // Google prefers several aspect ratios — offer the portrait poster + the 16:9 backdrop.
        $images = collect([$c->poster_url, $c->backdrop_url])
            ->map(fn ($p) => self::absolute($p))->filter()->unique()->values()->all();
        if ($images) {
            $node['image'] = $images;
        }
        if ($c->year) {
            $node['datePublished'] = (string) $c->year;
        }
        if ($c->updated_at) {
            $node['dateModified'] = $c->updated_at->toIso8601String();
        }
        if ($c->maturity) {
            $node['contentRating'] = $c->maturity;
        }
        if ($genres = $c->genres->pluck('name')->filter()->values()->all()) {
            $node['genre'] = $genres;
        }

        // Aggregate rating — only when real member ratings exist (Google rejects empty/zero ratings).
        $avg = round((float) $c->ratings->avg('stars'), 1);
        $count = $c->ratings->count();
        if ($count > 0 && $avg > 0) {
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $avg,
                'bestRating' => 5,
                'worstRating' => 1,
                'ratingCount' => $count,
            ];
        }

        // Where to watch it (playback is login-gated, which is a normal WatchAction target).
        $node['potentialAction'] = [
            '@type' => 'WatchAction',
            'target' => route('watch', $c),
        ];

        if ($isSeries) {
            $episodes = $c->episodes;
            $node['numberOfEpisodes'] = $episodes->count();

            if ($c->type === 'series' && $c->seasons->isNotEmpty()) {
                $node['containsSeason'] = $c->seasons->map(fn ($s) => array_filter([
                    '@type' => 'TVSeason',
                    'seasonNumber' => $s->number,
                    'name' => $s->title ?: ('ซีซั่น '.$s->number),
                    'numberOfEpisodes' => $s->episodes->count() ?: null,
                ]))->values()->all();
            }

            // Episode list (capped so a long short-drama doesn't bloat the page). URLs are omitted on
            // purpose: episodes live on this title page, not on separate public URLs.
            $node['episode'] = $episodes->take(60)->map(fn ($e) => array_filter([
                '@type' => 'TVEpisode',
                'episodeNumber' => $e->number,
                'name' => $e->title ?: ('ตอนที่ '.$e->number),
            ]))->values()->all();
        } elseif ($c->duration_minutes) {
            $node['duration'] = 'PT'.$c->duration_minutes.'M';
        }

        return $node;
    }

    /** Home → primary genre → title breadcrumb (all public URLs). */
    private static function breadcrumb(Content $c, string $url): array
    {
        $items = [['name' => 'หน้าแรก', 'url' => route('home')]];

        if ($genre = $c->primaryGenre()) {
            $items[] = ['name' => $genre->name, 'url' => route('browse.genre', $genre)];
        }
        $items[] = ['name' => $c->title, 'url' => $url];

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)->map(fn ($it, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $it['name'],
                'item' => $it['url'],
            ])->all(),
        ];
    }

    /** VideoObject for the YouTube trailer, when there is one (drives video rich results). */
    private static function trailer(Content $c): ?array
    {
        $yt = $c->youtube_id;
        if (! $yt) {
            return null;
        }

        return array_filter([
            '@type' => 'VideoObject',
            'name' => $c->title.' — ตัวอย่าง',
            'description' => Str::limit((string) $c->synopsis, 200) ?: $c->title,
            'thumbnailUrl' => ['https://i.ytimg.com/vi/'.$yt.'/hqdefault.jpg'],
            'uploadDate' => optional($c->created_at)->toDateString() ?? ($c->year ? $c->year.'-01-01' : null),
            'embedUrl' => 'https://www.youtube.com/embed/'.$yt,
            'contentUrl' => 'https://www.youtube.com/watch?v='.$yt,
        ]);
    }

    /** Absolutise a media URL — Google wants absolute image URLs in structured data. */
    private static function absolute(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return Str::startsWith($url, 'http') ? $url : url($url);
    }
}
