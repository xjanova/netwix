<?php

namespace Tests\Feature;

use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteSeries;
use App\Services\Import\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * wow-drama.com episode parsing — see [App\Services\Import\Sources\WowDramaSource].
 *
 * The fixtures keep this site's awkward markup rather than a tidied-up version of it: a newline
 * between the tag name and its attributes, and percent-encoded Thai slugs. Written the "clean" way,
 * a fixture here would have passed happily throughout the weeks fetchEpisodes was returning nothing.
 */
class WowDramaImportTest extends TestCase
{
    use RefreshDatabase;

    /** A real slug shape — WordPress percent-encodes Thai post_names, and this is how source_key is stored. */
    private const SLUG = 'the-whirlwind-%e0%b8%9e%e0%b8%b2%e0%b8%81%e0%b8%a2%e0%b9%8c%e0%b9%84%e0%b8%97%e0%b8%a2';

    /**
     * The regression: this theme emits `<button` and `class=` on separate lines, so a pattern with a
     * literal space between them matched none of the 81 buttons a real series page carries — and said
     * nothing, because "found no episodes" and "has no episodes" are the same answer from here.
     */
    public function test_episodes_are_found_when_the_class_sits_on_the_next_line(): void
    {
        Http::fake([
            'wow-drama.com/'.self::SLUG.'/' => Http::response(
                '<div class="mp-cover"></div><button'."\n".'class="mp-ep-btn mp-active" data-id="37870"><i></i></button>'
                .'<button'."\n".'class="mp-ep-btn" data-id="37871"><i></i></button>'
                .'<button'."\n".'class="mp-ep-btn" data-id="37918"><i></i></button>'
            ),
        ]);

        $episodes = app(SourceRegistry::class)->get('wowdrama')->fetchEpisodes($this->series());

        $this->assertCount(3, $episodes);
        $this->assertSame(['37870', '37871', '37918'], array_column($episodes, 'ref'));
        $this->assertSame([1, 2, 3], array_column($episodes, 'number'));
    }

    /** A page with no player must stay empty rather than invent an episode. */
    public function test_a_page_without_a_player_yields_no_episodes(): void
    {
        Http::fake(['wow-drama.com/'.self::SLUG.'/' => Http::response('<div>ยังไม่มีตอน</div>')]);

        $this->assertSame([], app(SourceRegistry::class)->get('wowdrama')->fetchEpisodes($this->series()));
    }

    /**
     * An empty episode list must not disturb a title already imported. This guard is the only reason
     * the parsing bug above cost nothing but freshness: importEpisodes prunes only when the fetch
     * returned something, so weeks of empty fetches left every stored episode intact. Worth pinning
     * down explicitly — the day it stops holding, a bad parse silently empties the catalogue.
     */
    public function test_an_empty_fetch_leaves_already_imported_episodes_alone(): void
    {
        // Re-faked between passes rather than sequenced: import() reads this page more than once
        // (episodes, then the synopsis scrape), so a fixed-length sequence would run dry on an
        // implementation detail that has nothing to do with what is under test.
        Http::fake([
            'wow-drama.com/'.self::SLUG.'/' => Http::response(
                '<button'."\n".'class="mp-ep-btn" data-id="1"></button><button'."\n".'class="mp-ep-btn" data-id="2"></button>'
            ),
        ]);

        $st = SourceTitle::create([
            'source' => 'wowdrama',
            'source_key' => self::SLUG,
            'title' => 'ดูซีรี่ส์ The Whirlwind (2024) แผนพลิกอำนาจ พากย์ไทย',
            'clean_title' => 'The Whirlwind',
            'extra' => ['slug' => self::SLUG],
        ]);

        $svc = app(ImportService::class);
        $content = $svc->import($st, ['type' => 'series', 'publish' => true]);
        $this->assertSame(2, $content->episodes()->count());

        Http::fake(['wow-drama.com/'.self::SLUG.'/' => Http::response('<div>the player failed to render today</div>')]);
        $svc->import($st->fresh(), ['type' => 'series', 'publish' => true]);

        $this->assertSame(2, $content->fresh()->episodes()->count());
    }

    private function series(): RemoteSeries
    {
        return new RemoteSeries(
            source: 'wowdrama',
            sourceKey: self::SLUG,
            title: 'ดูซีรี่ส์ The Whirlwind (2024) แผนพลิกอำนาจ พากย์ไทย',
            cleanTitle: 'The Whirlwind',
            extra: ['slug' => self::SLUG],
        );
    }
}
