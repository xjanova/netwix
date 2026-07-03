<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Genre;
use App\Models\SourceTitle;
use App\Models\User;
use App\Services\Import\ImportService;
use App\Services\Import\RemoteStream;
use App\Services\Import\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Anime108ImportTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAnime108(): void
    {
        Http::fake([
            'www.anime108.com/wp-json/wp/v2/categories*' => Http::response([
                ['id' => 63, 'name' => 'Fantasy', 'slug' => 'fantasy'],
                ['id' => 114, 'name' => 'อนิเมะพากย์ไทย', 'slug' => 'anime-thaidub'],
                ['id' => 117, 'name' => 'The Movie', 'slug' => 'the-movie'],
            ]),
            'www.anime108.com/wp-json/wp/v2/posts*' => Http::response([[
                'id' => 16393,
                'slug' => 'perfect-world',
                'title' => ['rendered' => 'Perfect World โลกอันสมบูรณ์แบบ พากย์ไทย'],
                'featured_media' => 100,
                'categories' => [63, 114],
                'date' => '2024-05-01T00:00:00',
            ]]),
            'www.anime108.com/wp-json/wp/v2/media*' => Http::response([
                ['id' => 100, 'source_url' => 'https://www.anime108.com/wp-content/uploads/pw.jpg'],
            ]),
            'www.anime108.com/perfect-world/' => Http::response(
                '<select>'
                .'<option value="/perfect-world-ep-1/" selected> ตอนที่ 1</option>'
                .'<option value="/perfect-world-ep-2/"> ตอนที่ 2</option>'
                .'</select>'
            ),
            'www.anime108.com/api/get.php' => Http::response(
                '<div class="embed-responsive embed-responsive-16by9">'
                .'<iframe class="embed-responsive-item" scrolling="no" '
                .'src="https://main.108player.com/index_th.php?id=abcdef0123456789abcdef01" allowfullscreen></iframe></div>'
            ),
            'main.108player.com/newplaylist/*' => Http::response(
                "#EXTM3U\n"
                ."#EXT-X-STREAM-INF:BANDWIDTH=500000,RESOLUTION=480x360\n"
                ."/m3u8/abcdef0123456789abcdef01/abcdef0123456789abcdef01168.m3u8\n"
                ."#EXT-X-STREAM-INF:BANDWIDTH=3000000,RESOLUTION=1920x1080\n"
                ."/m3u8/abcdef0123456789abcdef01/abcdef0123456789abcdef01438.m3u8\n"
            ),
        ]);
    }

    public function test_anime108_sync_and_import(): void
    {
        $this->fakeAnime108();
        $genre = Genre::create(['name' => 'อนิเมะ', 'slug' => 'anime']);
        $svc = app(ImportService::class);

        $synced = $svc->sync('anime108', 1);
        $this->assertSame(1, $synced);

        $st = SourceTitle::where('source', 'anime108')->firstOrFail();
        $this->assertSame('16393', $st->source_key);                       // WP post id, not slug
        $this->assertSame('Perfect World โลกอันสมบูรณ์แบบ', $st->clean_title); // dub tag stripped
        $this->assertSame('thai_dub', $st->dub_type);
        $this->assertSame(2024, $st->year);
        $this->assertSame('perfect-world', $st->extra['slug']);
        $this->assertSame('https://www.anime108.com/wp-content/uploads/pw.jpg', $st->poster_url);

        $content = $svc->import($st, ['type' => 'series', 'genres' => [$genre->id], 'publish' => true]);

        $this->assertDatabaseHas('contents', [
            'source' => 'anime108', 'source_key' => '16393', 'type' => 'series', 'is_published' => true,
        ]);
        $this->assertSame(2, $content->episodes()->count());
        $this->assertSame('anime108', $content->episodes()->first()->source);
        $this->assertSame('1', $content->episodes()->orderBy('number')->first()->source_ref);
        $this->assertTrue($content->genres->contains($genre->id));
    }

    public function test_anime108_resolve_returns_best_hls_variant(): void
    {
        $this->fakeAnime108();

        $stream = app(SourceRegistry::class)->get('anime108')->resolveByRef('16393', '1');

        $this->assertNotNull($stream);
        $this->assertSame(RemoteStream::KIND_HLS, $stream->kind);
        // Highest-bandwidth (1080p) variant, resolved to an absolute URL.
        $this->assertSame(
            'https://main.108player.com/m3u8/abcdef0123456789abcdef01/abcdef0123456789abcdef01438.m3u8',
            $stream->url,
        );
        $this->assertSame('https://main.108player.com/index_th.php?id=abcdef0123456789abcdef01', $stream->referer);
    }

    public function test_auto_type_splits_movies_and_auto_genres_maps_source_categories(): void
    {
        Http::fake([
            'www.anime108.com/some-movie/' => Http::response('<html>a movie page with no episode select</html>'),
        ]);
        $anime = Genre::create(['name' => 'อนิเมะ', 'slug' => 'anime']);
        $fantasy = Genre::create(['name' => 'แฟนตาซี & ไซไฟ', 'slug' => 'fantasy-scifi']);

        $st = SourceTitle::create([
            'source' => 'anime108', 'source_key' => '999', 'title' => 'Some Movie', 'clean_title' => 'Some Movie',
            'extra' => ['slug' => 'some-movie', 'is_movie' => true, 'genre_names' => ['อนิเมะ', 'แฟนตาซี & ไซไฟ']],
        ]);

        $content = app(ImportService::class)->import($st, [
            'type' => 'series', 'auto_type' => true, 'auto_genres' => true, 'publish' => true,
        ]);

        $this->assertSame('movie', $content->type);   // is_movie wins over the chosen 'series'
        $this->assertEqualsCanonicalizing([$anime->id, $fantasy->id], $content->genres->pluck('id')->all());
        $this->assertEquals(1, $content->genres->firstWhere('id', $anime->id)->pivot->is_primary); // umbrella = primary
    }

    public function test_dedupe_key_collapses_tags_and_flags_matches(): void
    {
        $this->assertSame(
            Content::dedupeKey('perfect world'),
            Content::dedupeKey('Perfect World (พากย์ไทย) HD'),
        );
        $this->assertNotSame(Content::dedupeKey('Naruto'), Content::dedupeKey('Bleach'));
    }

    public function test_auto_import_imports_next_chunk_and_reports_remaining(): void
    {
        Http::fake(['www.anime108.com/*' => Http::response('<html>a page with no episode select</html>')]);
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['a', 'b', 'c'] as $i => $s) {
            SourceTitle::create([
                'source' => 'anime108', 'source_key' => (string) (200 + $i),
                'title' => "T {$s}", 'clean_title' => "T {$s}",
                'view_count' => 10 - $i, 'extra' => ['slug' => "t-{$s}"],
            ]);
        }

        // First pass imports the top 2 (by view count), 1 left.
        $this->actingAs($admin)->postJson(route('admin.import.auto'), [
            'source' => 'anime108', 'type' => 'series', 'publish' => true, 'chunk' => 2,
        ])->assertOk()->assertJson(['ok' => 2, 'failed' => 0, 'remaining' => 1]);
        $this->assertSame(2, Content::where('source', 'anime108')->count());

        // Second pass drains the rest — nothing remaining, loop can stop.
        $this->actingAs($admin)->postJson(route('admin.import.auto'), [
            'source' => 'anime108', 'type' => 'series', 'publish' => true, 'chunk' => 2,
        ])->assertOk()->assertJson(['ok' => 1, 'remaining' => 0]);
        $this->assertSame(3, Content::where('source', 'anime108')->count());
    }
}
