<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\SourceTitle;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use App\Support\HlsManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * animeruka.com (Dooplay) — catalogue scrape from the /anime/ + /movies/ archives, episodes from the
 * series page, and the animemami→maimeorder HLS resolve chain (see [App\Services\Import\Sources\AnimerukaSource]).
 */
class AnimerukaImportTest extends TestCase
{
    use RefreshDatabase;

    private const MANIFEST = 'https://cdn2.maimeorder.com/hls/TESTTOKEN.txt';

    private function fakeAnimeruka(): void
    {
        // The animemami embed page carries the stream URL in an HTML-entity-encoded Inertia data-page JSON.
        $dataPage = htmlspecialchars(
            json_encode(['props' => ['video' => ['url' => self::MANIFEST, 'type' => 'direct']]]),
            ENT_QUOTES,
        );

        Http::fake([
            // /anime/ archive (page 1) — one series card. Double-quoted links, like the real theme.
            'animeruka.com/anime/' => Http::response(
                '<article id="post-42513" class="item tvshows"><div class="poster">'
                .'<img class="img-thumbnail" src="https://animeruka.com/wp-content/uploads/lv999.jpg" alt="x">'
                .'<span class="features-type">ซับไทย พากย์ไทย</span>'
                .'<a href="https://animeruka.com/anime/lv999-no-murabito/"><h3>'
                .'<div class="movie-title">Lv999 no Murabito ชาวบ้านคนนี้ LV999 พากย์ไทย</div></h3></a></div></article>'
            ),
            'animeruka.com/movies/' => Http::response('<html>no articles</html>'),

            // series page — the episodios list uses SINGLE-quoted attributes (theme quirk).
            'animeruka.com/anime/lv999-no-murabito/' => Http::response(
                "<ul class='episodios'>"
                ."<li class='mark-1'><div class='numerando'>ตอนที่ 1</div><div class='episodiotitle'>"
                ."<a href='https://animeruka.com/ep/lv999-no-murabito-ep-1/'>EP 1</a></div></li>"
                ."<li class='mark-2'><div class='numerando'>ตอนที่ 2</div><div class='episodiotitle'>"
                ."<a href='https://animeruka.com/ep/lv999-no-murabito-ep-2/'>EP 2</a></div></li></ul>"
            ),

            // episode page — Dooplay player options (single-quoted, data-type=tv).
            'animeruka.com/ep/lv999-no-murabito-ep-1/' => Http::response(
                "<ul id='playeroptionsul' class='ajax_mode'>"
                ."<li id='player-option-1' class='dooplay_player_option' data-type='tv' data-post='42519' data-nume='1'></li>"
                ."<li id='player-option-2' class='dooplay_player_option' data-type='tv' data-post='42519' data-nume='2'></li></ul>"
            ),

            // Dooplay resolve → animemami embed (server 1). Any nume returns the same here.
            'animeruka.com/wp-json/dooplayer/v2/*' => Http::response(
                ['embed_url' => 'https://animemami.xyz/v/TESTSLUG', 'type' => 'iframe'],
            ),

            // animemami embed page → its Inertia JSON with the maimeorder manifest URL.
            'animemami.xyz/v/*' => Http::response('<div id="app" data-page="'.$dataPage.'"></div>'),
        ]);
    }

    public function test_sync_scrapes_archive_into_source_titles(): void
    {
        $this->fakeAnimeruka();

        $synced = app(ImportService::class)->sync('animeruka', 1);
        $this->assertSame(1, $synced);

        $st = SourceTitle::where('source', 'animeruka')->firstOrFail();
        $this->assertSame('anime/lv999-no-murabito', $st->source_key);   // permalink path = unique key
        $this->assertSame('thai_dub', $st->dub_type);                    // "พากย์ไทย" badge wins
        $this->assertSame('https://animeruka.com/wp-content/uploads/lv999.jpg', $st->poster_url);
        $this->assertFalse((bool) ($st->extra['is_movie'] ?? true));
    }

    public function test_import_creates_episodes_from_series_page(): void
    {
        $this->fakeAnimeruka();
        $svc = app(ImportService::class);
        $svc->sync('animeruka', 1);
        $st = SourceTitle::where('source', 'animeruka')->firstOrFail();

        $content = $svc->import($st, ['type' => 'series', 'publish' => true]);

        $this->assertSame('series', $content->type);
        $this->assertSame(2, $content->episodes()->count());
        $this->assertSame('ep/lv999-no-murabito-ep-1', $content->episodes()->orderBy('number')->first()->source_ref);
        // Always filed under the "อนิเมะ" umbrella genre.
        $this->assertTrue($content->genres->contains(fn ($g) => $g->name === 'อนิเมะ'));
    }

    public function test_resolve_returns_animemami_hls_stream(): void
    {
        $this->fakeAnimeruka();

        $stream = app(SourceRegistry::class)->get('animeruka')
            ->resolveByRef('anime/lv999-no-murabito', 'ep/lv999-no-murabito-ep-1');

        $this->assertNotNull($stream);
        $this->assertSame(RemoteStream::KIND_HLS, $stream->kind);
        $this->assertSame(self::MANIFEST, $stream->url);
        $this->assertSame('https://animemami.xyz/', $stream->referer);   // maimeorder .txt is Referer-gated
    }

    public function test_hls_manifest_unwraps_json_base64_envelope(): void
    {
        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:10,\nblob/seg-000.webp\n";
        $wrapped = json_encode(['p' => base64_encode($playlist)]);

        $this->assertSame($playlist, HlsManifest::unwrap($wrapped));      // envelope → raw playlist
        $this->assertSame($playlist, HlsManifest::unwrap($playlist));     // already-raw passthrough
        $this->assertSame('{"x":1}', HlsManifest::unwrap('{"x":1}'));     // unrelated JSON untouched
    }
}
