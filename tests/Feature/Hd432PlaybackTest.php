<?php

namespace Tests\Feature;

use App\Services\Import\Sources\Hd432Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Hd432PlaybackTest extends TestCase
{
    private function fakePlayers(mixed $primaryMaster, mixed $primaryChild): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'hd432.com/film/' => Http::response('<iframe src="https://hd432.com/embed/?link=https://ssplayer168.xyz/api/embed/index.php?id=2810"></iframe>'),
            'ssplayer168.xyz/api/embed/jw/main.php*' => Http::response('<script>file="https://primary.test/master.m3u8"</script>'),
            'ssplayer168.xyz/api/embed/v5/index.php*' => Http::response('<script>file="https://backup.test/master.m3u8"</script>'),
            'primary.test/master.m3u8' => $primaryMaster,
            'primary.test/child.m3u8' => $primaryChild,
            'backup.test/master.m3u8' => Http::response("#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,URI=\"audio.m3u8\"\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nchild.m3u8\n"),
            'backup.test/child.m3u8' => Http::response("#EXTM3U\n#EXTINF:6,\nsegment.ts\n"),
        ]);
    }

    public function test_a_refused_cdn_rotates_to_the_next_player(): void
    {
        $this->fakePlayers(Http::response('Access denied', 403), Http::response('unused', 403));

        $stream = (new Hd432Source)->resolveByRef('film', '1');

        $this->assertNotNull($stream);
        $this->assertSame('https://backup.test/master.m3u8', $stream->url);
        $this->assertSame('https://ssplayer168.xyz/', $stream->referer);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://backup.test/child.m3u8'
            && $r->header('Referer') === ['https://ssplayer168.xyz/']);
    }

    public function test_a_live_master_with_a_dead_child_rotates_to_the_next_player(): void
    {
        $this->fakePlayers(Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nchild.m3u8\n"), Http::response('gone', 404));

        $this->assertSame('https://backup.test/master.m3u8', (new Hd432Source)->resolveByRef('film', '1')?->url);
    }

    public function test_a_playable_primary_does_not_fetch_another_player(): void
    {
        $this->fakePlayers(Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nchild.m3u8\n"), Http::response("#EXTM3U\n#EXTINF:6,\nsegment.ts\n"));

        $this->assertSame('https://primary.test/master.m3u8', (new Hd432Source)->resolveByRef('film', '1')?->url);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v5/'));
    }

    public function test_a_cdn_connection_failure_does_not_hide_a_working_player(): void
    {
        $this->fakePlayers(fn () => throw new ConnectionException('CDN timeout'), Http::response('unused', 403));

        $this->assertSame('https://backup.test/master.m3u8', (new Hd432Source)->resolveByRef('film', '1')?->url);
    }

    public function test_unplayable_candidates_do_not_return_a_false_stream(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'hd432.com/film/' => Http::response('<iframe src="https://hd432.com/embed/?link=https://ssplayer168.xyz/api/embed/index.php?id=2810"></iframe>'),
            'ssplayer168.xyz/*' => Http::response('<script>file="https://dead.test/master.m3u8"</script>'),
            'dead.test/*' => Http::response('blocked', 403),
        ]);

        $this->assertNull((new Hd432Source)->resolveByRef('film', '1'));
    }
}
