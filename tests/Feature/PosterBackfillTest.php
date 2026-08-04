<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\SourceTitle;
use App\Support\PosterBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cover healing against the two ways a source can refuse us but not a browser — see
 * [App\Support\PosterBackfill]. Both were live on netwix.online for weeks while the pages themselves
 * looked fine, because a poster that fails only on OUR side is invisible until someone counts.
 */
class PosterBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * Nearly half our poster URLs carry a Thai filename, and the source's nginx answers 400 to raw
     * UTF-8 in a path — the client has to percent-encode it exactly as a browser does. Guzzle already
     * does; this pins it, because a switch to a client that doesn't would silently kill the healer for
     * 11,000 titles while every one of their cards kept rendering fine.
     */
    public function test_a_thai_filename_goes_out_percent_encoded(): void
    {
        $sent = null;
        Http::fake(function (Request $r) use (&$sent) {
            $sent = $r->url();

            return Http::response(self::image(), 200, ['Content-Type' => 'image/jpeg']);
        });

        $path = $this->backfill()->recover($this->content(
            'https://wow-drama.com/wp-content/uploads/2023/09/แท็กซี่ชำระแค้น-1.jpg'
        ));

        $this->assertStringContainsString('%E0%B9%81%E0%B8%97', (string) $sent);
        $this->assertStringNotContainsString('แท็กซี่', (string) $sent);
        $this->assertNotNull($path, 'a Thai-named cover should be recoverable');
    }

    /**
     * anifume.com sits behind a Cloudflare rule that does the INVERSE of ordinary hotlink protection:
     * an empty Referer is 403'd and only its own site is served. Our cards send referrerpolicy=
     * no-referrer, so all 2,043 anifume titles showed the branded fallback instead of a cover.
     */
    public function test_a_referer_gated_host_is_recovered_on_the_retry(): void
    {
        Http::fake(fn (Request $r) => $r->header('Referer') === ['https://anifume.com/']
            ? Http::response(self::image(), 200, ['Content-Type' => 'image/jpeg'])
            : Http::response('<title>Attention Required! | Cloudflare</title>', 403));

        $path = $this->backfill()->recover($this->content('https://anifume.com/images/07/Takopii.jpg'));

        $this->assertNotNull($path, 'the same-origin Referer retry should pull the cover down');
        $this->assertStringStartsWith('media/posters/', $path);
        Storage::disk('public')->assertExists($path);
    }

    /**
     * urlAlive is the browser's verdict, not ours: a cover we can only fetch WITH a Referer is dead to
     * every visitor, so the --check sweep has to keep flagging it — otherwise the one case that most
     * needs downloading to local storage is the one case it would skip.
     */
    public function test_a_referer_gated_url_still_counts_as_dead(): void
    {
        Http::fake(fn (Request $r) => $r->header('Referer') === ['https://anifume.com/']
            ? Http::response(self::image(), 200, ['Content-Type' => 'image/jpeg'])
            : Http::response('denied', 403));

        $this->assertFalse($this->backfill()->urlAlive('https://anifume.com/images/07/Takopii.jpg'));
    }

    /**
     * Import seeds poster_path and backdrop_path from one source URL — 23,702 of 23,761 titles hold
     * the identical string. So a poster we've just proved dead means the backdrop behind the hero and
     * the title modal is the same dead URL, and healing only the poster leaves those a bare gradient.
     */
    public function test_the_backdrop_follows_the_poster_when_it_was_the_same_dead_image(): void
    {
        $c = $this->content('https://anifume.com/images/07/Takopii.jpg');
        $c->forceFill(['backdrop_path' => 'https://anifume.com/images/07/Takopii.jpg'])->save();

        $this->backfill()->apply($c, 'media/posters/9-abc.webp');

        $this->assertSame('media/posters/9-abc.webp', $c->fresh()->backdrop_path);
    }

    /** A backdrop that is its own image (an admin-set one, say) is not overwritten by a cover heal. */
    public function test_a_distinct_backdrop_is_left_alone(): void
    {
        $c = $this->content('https://anifume.com/images/07/Takopii.jpg');
        $c->forceFill(['backdrop_path' => 'media/backdrops/9-own.webp'])->save();

        $this->backfill()->apply($c, 'media/posters/9-abc.webp');

        $this->assertSame('media/backdrops/9-own.webp', $c->fresh()->backdrop_path);
    }

    /** A hotlink-blocked host that answers 200 with an HTML "denied" page is not a cover. */
    public function test_an_html_denial_page_is_not_accepted_as_an_image(): void
    {
        Http::fake(fn () => Http::response(str_repeat('<html>denied</html>', 60), 200, ['Content-Type' => 'text/html']));

        $this->assertNull($this->backfill()->recover($this->content('https://x.test/p.jpg')));
    }

    private function backfill(): PosterBackfill
    {
        return app(PosterBackfill::class);
    }

    /** A title whose only poster candidate is $url (no ProvidesPoster source involved). */
    private function content(string $url): Content
    {
        $c = Content::create([
            'title' => 'ทดสอบปก', 'slug' => 'test-cover', 'type' => 'movie',
            'is_published' => true, 'maturity' => '13+',
        ]);
        $c->forceFill(['source' => 'anifume', 'source_key' => 'k1', 'poster_path' => $url])->save();
        SourceTitle::create(['source' => 'anifume', 'source_key' => 'k1', 'title' => 'ทดสอบปก', 'poster_url' => $url]);

        return $c;
    }

    /** A real, GD-decodable poster — ImageStore re-encodes what it downloads, so bogus bytes won't do. */
    private static function image(): string
    {
        $im = imagecreatetruecolor(200, 300);
        imagefilledrectangle($im, 0, 0, 200, 150, imagecolorallocate($im, 220, 40, 60));
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }
}
