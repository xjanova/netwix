<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use App\Support\TitleIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The directory exists because the hubs shuffle daily and so give no title a stable referring page.
 * Its value is entirely in being complete and stable, so that is what these tests hold it to.
 */
class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int,string> $titles */
    private function publish(array $titles): void
    {
        $genre = Genre::create(['name' => 'ดราม่า', 'slug' => 'drama']);
        foreach ($titles as $i => $t) {
            $c = Content::create([
                'title' => $t, 'slug' => 'dir-fixture-'.$i, 'type' => 'series',
                'synopsis' => 'ย่อ', 'year' => 2026, 'maturity' => '13+', 'is_published' => true,
            ]);
            $c->genres()->attach($genre->id, ['is_primary' => true]);
        }
        Cache::forget('directory:counts');
    }

    public function test_the_index_lists_every_populated_group_and_no_empty_one(): void
    {
        $this->publish(['Alpha', 'avalanche', 'กระทิง', '7 Days', '【special】']);

        $html = $this->get(route('browse.all'))->assertStatus(200)->getContent();

        foreach (['a', 'th-e01', TitleIndex::DIGITS, TitleIndex::OTHER] as $slug) {
            $this->assertStringContainsString(route('browse.all.group', $slug), $html, "group {$slug} should be listed");
        }
        $this->assertStringNotContainsString(route('browse.all.group', 'z'), $html, 'an empty group must not be linked');
    }

    /** Every published title lands in exactly one group — including the odd ones. */
    public function test_the_groups_account_for_every_published_title(): void
    {
        $titles = ['Alpha', 'avalanche', 'กระทิง', 'เรื่องเล่า', '7 Days', '【special】', 'Тест'];
        $this->publish($titles);

        $this->assertSame(count($titles), array_sum(TitleIndex::counts()));
    }

    public function test_a_group_page_links_each_title_by_name(): void
    {
        $this->publish(['Alpha', 'avalanche', 'Beta']);

        $a = Content::where('title', 'Alpha')->first();
        $html = $this->get(route('browse.all.group', 'a'))->assertStatus(200)->getContent();

        $this->assertStringContainsString(route('title.show', $a), $html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('avalanche', $html, 'the group is case-folded');
        $this->assertStringNotContainsString(route('title.show', Content::where('title', 'Beta')->first()), $html);
    }

    /** Case-folding is the collation's job; this pins the behaviour we verified against prod. */
    public function test_upper_and_lower_case_share_one_group(): void
    {
        $this->publish(['Alpha', 'avalanche']);

        $this->assertSame(2, TitleIndex::counts()['a'] ?? 0);
    }

    public function test_a_group_with_no_titles_is_404_not_an_empty_page(): void
    {
        $this->publish(['Alpha']);

        $this->get(route('browse.all.group', 'z'))->assertNotFound();
    }

    public function test_an_unknown_group_slug_is_404(): void
    {
        $this->publish(['Alpha']);

        $this->get('/all/not-a-letter')->assertNotFound();
        $this->get('/all/th-ffff')->assertNotFound();
    }

    /** A slug must survive the round trip, or sitemap entries and links drift apart. */
    public function test_slugs_round_trip(): void
    {
        foreach (['Alpha' => 'a', 'กระทิง' => 'th-e01', 'เรื่อง' => 'th-e40', '7 Days' => '0-9', '【x】' => 'other'] as $title => $slug) {
            $this->assertSame($slug, TitleIndex::slugFor($title), "slug for {$title}");
            $this->assertTrue(TitleIndex::isValid($slug));
        }

        $this->assertSame('ก', TitleIndex::charFor('th-e01'));
        $this->assertSame('A', TitleIndex::label('a'));
        $this->assertSame('ก', TitleIndex::label('th-e01'));
    }

    /** Imported titles sometimes carry leading whitespace; they must not all fall into "other". */
    public function test_a_leading_space_does_not_change_a_titles_group(): void
    {
        $this->assertSame('a', TitleIndex::slugFor('  Alpha'));
    }

    /** The sitemap must advertise the directory, and only groups that answer 200. */
    public function test_the_sitemap_lists_the_directory_and_its_populated_groups(): void
    {
        $this->publish(['Alpha', 'กระทิง']);

        $xml = $this->get(route('sitemap.pages'))->assertStatus(200)->getContent();

        $this->assertStringContainsString(route('browse.all'), $xml);
        $this->assertStringContainsString(route('browse.all.group', 'a'), $xml);
        $this->assertStringContainsString(route('browse.all.group', 'th-e01'), $xml);
        $this->assertStringNotContainsString(route('browse.all.group', 'z'), $xml);
    }

    /** The whole point: the same URL returns the same titles in the same order tomorrow. */
    public function test_group_ordering_is_stable_across_requests(): void
    {
        $this->publish(['Ant', 'Apple', 'Anchor', 'Avocado', 'Arrow']);

        $first = $this->get(route('browse.all.group', 'a'))->getContent();
        $second = $this->get(route('browse.all.group', 'a'))->getContent();

        preg_match_all('#/title/(dir-fixture-\d+)#', $first, $a);
        preg_match_all('#/title/(dir-fixture-\d+)#', $second, $b);

        $this->assertNotEmpty($a[1]);
        $this->assertSame($a[1], $b[1], 'the directory must not reshuffle — that is the hubs\' job, not its');
    }

    /** Every page of the footer reaches it, because that is how a crawler finds the directory at all. */
    public function test_the_footer_links_the_directory(): void
    {
        $this->publish(['Alpha']);

        $this->get(route('browse.movies'))->assertStatus(200)->assertSee(route('browse.all'), false);
    }
}
