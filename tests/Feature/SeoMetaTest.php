<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The <head> meta block is the only thing Google reads about a title page, so the two ways it can
 * be silently wrong both get a test here: text that arrives double-escaped, and the Search Console
 * proof that has to be present for the sitemap to ever be submitted.
 */
class SeoMetaTest extends TestCase
{
    use RefreshDatabase;

    private function publishedTitle(string $title, string $synopsis): Content
    {
        $genre = Genre::create(['name' => 'ดราม่า', 'slug' => 'drama']);
        $content = Content::create([
            'title' => $title, 'slug' => 'seo-meta-fixture', 'type' => 'series',
            'synopsis' => $synopsis, 'year' => 2026, 'maturity' => '13+',
            'is_published' => true,
        ]);
        $content->genres()->attach($genre->id, ['is_primary' => true]);

        return $content;
    }

    /**
     * Regression: Blade's value form — @section('meta_description', $desc) — runs the value through
     * e() before storing it, and the {{ }} that printed it escaped it a second time. A synopsis with
     * a plain " reached Google as &amp;quot; and the snippet showed the entity, literally. 1,669 of
     * 19,933 published titles carried a quote, & or apostrophe and so had a mangled snippet.
     */
    public function test_meta_description_and_title_are_escaped_exactly_once(): void
    {
        $content = $this->publishedTitle(
            'Tom & Jerry "ฉบับพิเศษ"',
            'เธอคือ"สะใภ้เลี้ยง"ผู้ถูกชะตาทอดทิ้ง & ถูกตราหน้าว่าอัปมงคล',
        );

        $html = $this->get(route('title.show', $content))->assertStatus(200)->getContent();

        // One round of escaping is correct and required — it is what keeps the attribute intact.
        // Two rounds is the bug, and it shows up as the escaped form of an entity.
        $this->assertStringNotContainsString('&amp;quot;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);

        preg_match('/<meta name="description" content="([^"]*)"/', $html, $desc);
        $this->assertNotEmpty($desc, 'the title page must emit a meta description');
        $this->assertStringContainsString('&quot;สะใภ้เลี้ยง&quot;', $desc[1]);
        $this->assertStringContainsString('&amp; ถูกตราหน้า', $desc[1]);

        preg_match('/<title>([^<]*)<\/title>/', $html, $pageTitle);
        $this->assertStringContainsString('Tom &amp; Jerry &quot;ฉบับพิเศษ&quot;', $pageTitle[1]);
    }

    /**
     * The site has never been verified in Search Console, so its sitemap — the only route by which
     * Google can learn that 18,000+ title pages exist — has never been submitted. The owner can now
     * paste the code at /admin/seo, and Google shows it as a whole <meta> tag, so accept that too.
     */
    public function test_search_console_verification_renders_from_either_paste(): void
    {
        $content = $this->publishedTitle('เรื่องทดสอบ', 'ย่อ');

        // Nothing set → no empty tag left lying in the head.
        $this->get(route('title.show', $content))
            ->assertStatus(200)
            ->assertDontSee('google-site-verification', false);

        foreach ([
            'abc123TOKEN_xyz',
            '<meta name="google-site-verification" content="abc123TOKEN_xyz" />',
        ] as $pasted) {
            Setting::write('seo_google_verification', $pasted);   // write() invalidates the cache

            $this->get(route('title.show', $content))
                ->assertStatus(200)
                ->assertSee('<meta name="google-site-verification" content="abc123TOKEN_xyz">', false);
        }
    }
}
