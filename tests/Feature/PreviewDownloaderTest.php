<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Services\PreviewDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PreviewDownloaderTest extends TestCase
{
    use RefreshDatabase;

    private function rongyokContent(array $ep = []): Content
    {
        $c = Content::create([
            'title' => 'เรื่องแนวตั้ง', 'slug' => 'v1', 'source' => 'rongyok', 'source_key' => '8207',
            'type' => 'vertical', 'is_published' => true, 'maturity' => '15+',
        ]);
        $c->episodes()->create(array_merge(
            ['number' => 1, 'title' => 'ตอนที่ 1', 'source' => 'rongyok', 'source_ref' => '1'],
            $ep,
        ));

        return $c;
    }

    public function test_skips_and_never_hits_network_when_ep1_already_stored(): void
    {
        Http::fake();
        $c = $this->rongyokContent(['video_url' => 'https://netwix.online/storage/media/rongyok/8207/1.mp4']);

        $this->assertNull(app(PreviewDownloader::class)->downloadFirstEpisode($c));
        Http::assertNothingSent();
    }

    public function test_skips_when_content_has_no_source(): void
    {
        $c = Content::create(['title' => 'manual', 'slug' => 'm1', 'type' => 'movie', 'is_published' => true, 'maturity' => '13+']);
        $c->episodes()->create(['number' => 1, 'title' => 'ตอนที่ 1']);

        $this->assertNull(app(PreviewDownloader::class)->downloadFirstEpisode($c));
    }

    public function test_returns_null_when_source_hands_back_an_expired_url(): void
    {
        Cache::flush();
        $c = $this->rongyokContent();

        $expired = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() - 3600).'&is=x&hm=y';
        Http::fake([
            'rongyok.com/watch/watch.js' => Http::response('fetch(`/watch/xq7bza9k.php?series_id=`)'),
            'rongyok.com/watch/xq7bza9k.php*' => Http::response(['ok' => true, 'video_url' => $expired]),
        ]);

        $this->assertNull(app(PreviewDownloader::class)->downloadFirstEpisode($c));
        $this->assertNull($c->episodes()->where('number', 1)->first()->video_url);
    }
}
