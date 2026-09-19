<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue is only worth anything to search if a crawler can walk into it. These tests guard
 * the walk itself: pagination must be followable, and it must be shallow.
 */
class CatalogCrawlPathTest extends TestCase
{
    use RefreshDatabase;

    /** 250 published series → 5 pages at 60 per page, enough for a window plus a "last page" jump. */
    private function seedSeries(int $n = 250): void
    {
        $genre = Genre::create(['name' => 'ดราม่า', 'slug' => 'drama']);
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = [
                'title' => "เรื่องทดสอบ {$i}", 'slug' => "crawl-fixture-{$i}", 'type' => 'series',
                'synopsis' => 'ย่อ', 'year' => 2026, 'maturity' => '13+', 'is_published' => true,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        Content::insert($rows);
        Content::query()->each(fn (Content $c) => $c->genres()->attach($genre->id, ['is_primary' => true]));
    }

    /**
     * Regression: every link in the pager carried rel="nofollow". robots.txt allowed ?page=, the
     * deeper pages self-canonicalised, the sitemap listed all 19,756 titles — and none of it mattered,
     * because the only links into the catalogue told Google not to follow them. Search Console
     * reported "referring page: none" on title pages while all of that was in place.
     */
    public function test_pagination_links_are_followable(): void
    {
        $this->seedSeries();

        $html = $this->get(route('browse.series'))->assertStatus(200)->getContent();

        preg_match_all('/<a[^>]+href="[^"]*[?&]page=\d+[^"]*"[^>]*>/', $html, $pageLinks);
        $this->assertNotEmpty($pageLinks[0], 'the hub must link to its other pages');

        foreach ($pageLinks[0] as $tag) {
            $this->assertStringNotContainsString('nofollow', $tag,
                'a pagination link must not be nofollowed — it is the crawl path into the catalogue');
        }
    }

    /**
     * The hub used to offer only "‹ ก่อนหน้า / ถัดไป ›", which put page 134 of /series 133 clicks from
     * page 1. A crawler spending a handful of requests a day never arrives.
     */
    public function test_the_catalogue_is_reachable_without_walking_every_page(): void
    {
        $this->seedSeries();

        $html = $this->get(route('browse.series'))->assertStatus(200)->getContent();

        preg_match_all('/[?&]page=(\d+)/', $html, $m);
        $pages = array_map('intval', $m[1]);

        $this->assertContains(5, $pages, 'the last page must be linked directly from page 1');
        $this->assertGreaterThanOrEqual(3, count(array_unique($pages)),
            'page 1 should expose several pages, not just the next one');
    }

    /** Sort permutations are duplicate views of the same set — those stay nofollowed. */
    public function test_sort_variants_stay_nofollowed(): void
    {
        $this->seedSeries(80);

        $html = $this->get(route('browse.series'))->assertStatus(200)->getContent();

        preg_match_all('/<a[^>]+href="[^"]*[?&]sort=[^"]*"[^>]*>/', $html, $sortLinks);
        $this->assertNotEmpty($sortLinks[0], 'the hub renders a sort bar');

        foreach ($sortLinks[0] as $tag) {
            $this->assertStringContainsString('nofollow', $tag,
                'sort variants must stay nofollowed — they multiply the same page');
        }
    }
}
