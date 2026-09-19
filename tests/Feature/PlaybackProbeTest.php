<?php

namespace Tests\Feature;

use App\Services\Import\RemoteStream;
use App\Support\PlaybackProbe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The canary calls this to decide whether a source is alive. Its whole reason to exist is the
 * wow-drama outage: resolve() was fine, the master playlist was fine, and every viewer got nothing
 * because the master's CHILD answered 403. A probe that stops before the child cannot see that.
 */
class PlaybackProbeTest extends TestCase
{
    private const REFERER = 'https://getplay-cdn.com/embed/abc';

    private const MASTER = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1920x1080\nhttps://cdn.test/s/abc/1080p/variant.m3u8\n";

    private const MEDIA = "#EXTM3U\n#EXT-X-TARGETDURATION:5\n#EXTINF:4.8,\nhttps://cdn.test/s/abc/seg0.ts\n";

    private function hls(): RemoteStream
    {
        return new RemoteStream(RemoteStream::KIND_HLS, 'https://cdn.test/s/abc/index.m3u8', self::REFERER);
    }

    /** The exact shape of the wow-drama outage: master 200, child 403. */
    public function test_a_master_whose_child_is_refused_does_not_count_as_playing(): void
    {
        Http::fake([
            'cdn.test/s/abc/index.m3u8' => Http::response(self::MASTER),
            'cdn.test/s/abc/1080p/variant.m3u8' => Http::response('<html>403 Forbidden</html>', 403),
        ]);

        $this->assertFalse(PlaybackProbe::plays($this->hls()),
            'a source whose variants are refused is down, however healthy its master looks');
    }

    public function test_a_master_whose_child_serves_segments_counts_as_playing(): void
    {
        Http::fake([
            'cdn.test/s/abc/index.m3u8' => Http::response(self::MASTER),
            'cdn.test/s/abc/1080p/variant.m3u8' => Http::response(self::MEDIA),
        ]);

        $this->assertTrue(PlaybackProbe::plays($this->hls()));
    }

    /** Single-rendition sources (most of the pool) must keep passing — no extra fetch, no regression. */
    public function test_a_plain_media_playlist_counts_as_playing(): void
    {
        Http::fake(['cdn.test/s/abc/index.m3u8' => Http::response(self::MEDIA)]);

        $this->assertTrue(PlaybackProbe::plays($this->hls()));
    }

    /** A playlist that lists nothing is not playable, even though it parses as one. */
    public function test_an_empty_playlist_does_not_count_as_playing(): void
    {
        Http::fake(['cdn.test/s/abc/index.m3u8' => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:5\n")]);

        $this->assertFalse(PlaybackProbe::plays($this->hls()));
    }

    /** Relative child URIs are as common as absolute ones and must resolve against the master. */
    public function test_a_relative_child_uri_is_resolved_against_the_master(): void
    {
        Http::fake([
            'cdn.test/s/abc/index.m3u8' => Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n720p/variant.m3u8\n"),
            'cdn.test/s/abc/720p/variant.m3u8' => Http::response(self::MEDIA),
        ]);

        $this->assertTrue(PlaybackProbe::plays($this->hls()));
    }

    public function test_an_mp4_is_probed_with_a_single_byte(): void
    {
        Http::fake(['cdn.test/movie.mp4' => Http::response('x', 206, ['Content-Type' => 'video/mp4'])]);

        $this->assertTrue(PlaybackProbe::plays(
            new RemoteStream(RemoteStream::KIND_MP4, 'https://cdn.test/movie.mp4', null)
        ));
    }

    public function test_a_dead_mp4_does_not_count_as_playing(): void
    {
        Http::fake(['cdn.test/movie.mp4' => Http::response('<html>Not Found</html>', 404)]);

        $this->assertFalse(PlaybackProbe::plays(
            new RemoteStream(RemoteStream::KIND_MP4, 'https://cdn.test/movie.mp4', null)
        ));
    }
}
