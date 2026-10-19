<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Profile;
use App\Models\SourceTitle;
use App\Models\User;
use App\Services\Import\EpisodeRefresher;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteSeries;
use App\Services\Import\Sources\RongYokSource;
use App\Support\AdvertCover;
use App\Support\PosterBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RongYokImportSmarterTest extends TestCase
{
    use RefreshDatabase;

    private const PLOT = 'ปกรณ์ หนุ่มธรรมดาที่ได้ระบบทักษะสารพัด เปลี่ยนชีวิตจากคนทำงานธรรมดาให้กลายเป็นคนสำคัญ';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** A PNG that is $greenShare green and the rest grey, at the given size. */
    private function png(float $greenShare, int $w = 336, int $h = 486): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 90, 90));
        imagefilledrectangle($im, 0, 0, $w - 1, (int) round($h * $greenShare) - 1, imagecolorallocate($im, 20, 110, 40));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function watchPage(string $description = self::PLOT, int $episodes = 3): string
    {
        $eps = implode(',', array_map(fn ($n) => '{"id":'.(100 + $n).',"episode_number":'.$n.'}', range(1, $episodes)));

        return '<meta name="description" content="ดูเรื่อง ซับไทย ออนไลน์ฟรี ดูได้ทันที ไม่ต้องสมัคร">'
            .'<script type="module">const seriesData = {"id":8457,"title":"เรื่องทดสอบsub","description":'
            .json_encode($description, JSON_UNESCAPED_UNICODE).',"view_count":4028,"episodes_count":'.$episodes
            .',"episodes":['.$eps.']}; handleEpisodesLoaded(seriesData);</script>';
    }

    public function test_the_green_advert_card_is_recognised_only_where_it_can_come_from(): void
    {
        $this->assertTrue(AdvertCover::isAdvertHotlink('https://rongyok.com/images/poster/x.webp'));
        $this->assertFalse(AdvertCover::isAdvertHotlink('https://cdn.example.test/x.webp'));
        $this->assertFalse(AdvertCover::isAdvertHotlink('media/posters/1.webp'));
        $this->assertTrue(AdvertCover::isAdvertRedirect('https://rongyok.com/images/anti1.png'));
        $this->assertTrue(AdvertCover::isAdvertRedirect('https://www.rongyok.com/images/anti2.png'));
        $this->assertFalse(AdvertCover::isAdvertRedirect('https://rongyok.com/images/poster/anti1.png'));

        $card = $this->png(0.7);
        $this->assertTrue(AdvertCover::looksLikeAdvert($card, 'https://rongyok.com/images/poster/x.webp'));
        // A genuinely green poster from another site is not the card (anime posters reach 0.77).
        $this->assertFalse(AdvertCover::looksLikeAdvert($card, 'https://anime.example.test/x.webp'));
        // Real rongyok covers measured at most 0.49.
        $this->assertFalse(AdvertCover::looksLikeAdvert($this->png(0.45), 'https://rongyok.com/images/poster/x.webp'));
        // The card's exact size lowers the bar for a re-encoded copy.
        $this->assertTrue(AdvertCover::looksLikeAdvert($this->png(0.4, 1086, 1448), 'https://rongyok.com/images/poster/x.webp'));
    }

    public function test_a_downloaded_advert_is_never_kept_as_a_cover(): void
    {
        Http::fake([
            'rongyok.com/images/poster/advert.webp' => Http::response($this->png(0.7), 200, ['Content-Type' => 'image/png']),
            'rongyok.com/images/poster/real.webp' => Http::response($this->png(0.1), 200, ['Content-Type' => 'image/png']),
        ]);
        $backfill = app(PosterBackfill::class);
        $this->assertNull($backfill->fetchImage('https://rongyok.com/images/poster/advert.webp'));
        $this->assertNotNull($backfill->fetchImage('https://rongyok.com/images/poster/real.webp'));
    }

    public function test_viewers_never_get_a_rongyok_image_url(): void
    {
        $c = new Content(['poster_path' => 'https://rongyok.com/images/poster/x.webp', 'backdrop_path' => 'https://cdn.example.test/b.webp']);
        $this->assertNull($c->poster_url, 'branded fallback instead of the advert');
        $this->assertSame('https://cdn.example.test/b.webp', $c->backdrop_url);
        $this->assertStringContainsString('media/posters/1.webp', (new Content(['poster_path' => 'media/posters/1.webp']))->poster_url);
    }

    public function test_the_localize_sweep_points_hotlinked_backdrops_at_our_poster(): void
    {
        $c = Content::create(['title' => 'ฉากหลัง', 'slug' => 'bd', 'source' => 'rongyok', 'source_key' => '1', 'type' => 'vertical',
            'maturity' => '15+', 'is_published' => true, 'poster_path' => 'media/posters/1-x.webp',
            'backdrop_path' => 'https://rongyok.com/images/poster/1.jpg']);
        $this->artisan('netwix:backfill-posters --localize --limit=10')->assertSuccessful();
        $this->assertSame('media/posters/1-x.webp', $c->fresh()->backdrop_path);
    }

    public function test_one_series_page_gives_episodes_and_the_plot(): void
    {
        Http::fake(['rongyok.com/watch/*' => Http::response($this->watchPage())]);
        $source = app(RongYokSource::class);
        $rs = new RemoteSeries(source: 'rongyok', sourceKey: '8457', title: 'เรื่องทดสอบ', cleanTitle: 'เรื่องทดสอบ');
        $this->assertSame([1, 2, 3], array_column($source->fetchEpisodes($rs), 'number'));
        $this->assertSame(self::PLOT, $source->fetchSynopsis($rs));
        Http::assertSentCount(1);
    }

    public function test_an_seo_line_is_not_taken_for_a_plot(): void
    {
        Http::fake(['rongyok.com/watch/*' => Http::response($this->watchPage('ดูเรื่องทดสอบ ซับไทย ออนไลน์ฟรี ดูได้ทันที'))]);
        $this->assertNull(app(RongYokSource::class)->fetchSynopsis(new RemoteSeries(source: 'rongyok', sourceKey: '8457', title: 'x', cleanTitle: 'x')));
    }

    public function test_a_listing_without_a_field_does_not_erase_what_we_stored(): void
    {
        SourceTitle::create(['source' => 'rongyok', 'source_key' => '8203', 'title' => 'บ่วงรัก', 'description' => 'เรื่องย่อเดิม',
            'view_count' => 1234, 'extra' => ['unplayable' => false, 'note' => 'kept']]);
        $card = '<div class="movie-card"><a href="/watch/?series_id=8203"><img src="images/poster/บ่วงรัก-พากย์ไทย-2025-8203.webp" alt="บ่วงรัก"></a></div>';
        Http::fake(['rongyok.com/' => Http::response('<script>var seriesData = [{"id":8203,"title":"บ่วงรัก","poster_url":"images/poster/บ่วงรัก-พากย์ไทย-2025-8203.webp","view_count":0}];</script>'.$card),
            'rongyok.com/category*' => Http::response('')]);
        app(ImportService::class)->sync('rongyok', 1);
        $st = SourceTitle::where('source_key', '8203')->first();
        $this->assertSame('เรื่องย่อเดิม', $st->description);
        $this->assertSame(1234, $st->view_count);
        $this->assertSame('kept', $st->extra['note']);
    }

    public function test_a_reimport_keeps_edited_titles_and_plots_and_fills_blanks(): void
    {
        Http::fake(['rongyok.com/watch/*' => Http::response($this->watchPage(episodes: 5)), 'rongyok.com/images/*' => Http::response('', 404)]);
        $st = SourceTitle::create(['source' => 'rongyok', 'source_key' => '8457', 'title' => 'ชื่อจากต้นทาง']);
        $svc = app(ImportService::class);
        $content = $svc->import($st, ['type' => 'vertical']);
        $this->assertSame(self::PLOT, $content->synopsis, 'a new title gets its plot from the series page');
        $content->update(['title' => 'ชื่อที่แอดมินแก้', 'synopsis' => 'เรื่องย่อที่แอดมินเขียน']);
        $again = $svc->import($st->fresh(), ['type' => 'vertical']);
        $this->assertSame(['ชื่อที่แอดมินแก้', 'เรื่องย่อที่แอดมินเขียน'], [$again->title, $again->synopsis]);
        $this->assertSame(5, $again->episodes()->count());
    }

    public function test_still_airing_verticals_are_picked_watched_first(): void
    {
        $make = function (string $key, string $title, int $ageDays) {
            $c = Content::create(['title' => $title, 'slug' => 'v'.$key, 'source' => 'rongyok', 'source_key' => $key, 'type' => 'vertical', 'maturity' => '15+', 'is_published' => true]);
            DB::table('contents')->where('id', $c->id)->update(['created_at' => now()->subDays($ageDays)]);
            $c->episodes()->create(['number' => 1, 'title' => 'ep1', 'source' => 'rongyok', 'source_ref' => '1']);
            DB::table('episodes')->where('content_id', $c->id)->update(['created_at' => now()->subDays($ageDays)]);
            SourceTitle::create(['source' => 'rongyok', 'source_key' => $key, 'title' => $title, 'content_id' => $c->id]);

            return $c;
        };
        $new = $make('1', 'ใหม่', 10);
        $old = $make('2', 'เก่านิ่ง', 120);
        $growing = $make('3', 'เก่ายังมีตอนใหม่', 120);
        $growing->episodes()->create(['number' => 2, 'title' => 'ep2', 'source' => 'rongyok', 'source_ref' => '2']);
        $ended = $make('4', 'ใหม่แต่ จบ แล้ว', 5);
        $watched = $make('5', 'มีคนดูอยู่', 30);
        $profile = Profile::create(['user_id' => User::factory()->create()->id, 'name' => 'p', 'avatar_color' => '#000']);
        DB::table('watch_progress')->insert(['profile_id' => $profile->id, 'content_id' => $watched->id,
            'episode_id' => $watched->episodes()->value('id'), 'percent' => 50, 'position_seconds' => 10,
            'last_watched_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        $refresher = app(EpisodeRefresher::class);
        $picked = $refresher->airingVerticals($refresher->query('rongyok', true))->get()->pluck('title')->all();
        $this->assertSame('มีคนดูอยู่', $picked[0], 'a title someone is watching goes first');
        $this->assertEqualsCanonicalizing(['มีคนดูอยู่', 'ใหม่', 'เก่ายังมีตอนใหม่'], $picked);
        $this->assertNotContains($old->title, $picked);
        $this->assertNotContains($ended->title, $picked);
    }

    public function test_synopsis_backfill_does_not_keep_asking_empty_pages(): void
    {
        Http::fake(['rongyok.com/watch/*' => Http::response($this->watchPage('สั้น'))]);
        SourceTitle::create(['source' => 'rongyok', 'source_key' => '8457', 'title' => 'ไม่มีเรื่องย่อ']);
        $this->artisan('netwix:backfill-synopsis rongyok --limit=10')->assertSuccessful();
        Http::assertSentCount(1);
        $this->artisan('netwix:backfill-synopsis rongyok --limit=10')->assertSuccessful();
        Http::assertSentCount(1);
    }
}
