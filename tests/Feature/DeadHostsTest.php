<?php

namespace Tests\Feature;

use App\Http\Controllers\StreamController;
use App\Models\Content;
use App\Services\Import\RemoteStream;
use App\Support\DeadHosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A stream host whose domain was dropped (maimestorage.xyz, 2026-11) must cost one viewer one wait,
 * not every viewer every time — and must never be confused with our own resolver being down.
 */
class DeadHostsTest extends TestCase
{
    use RefreshDatabase;

    private const DEAD = 'https://cdn10.maimestorage.test/hls/ep1/index.m3u8';

    protected function tearDown(): void
    {
        DeadHosts::$resolver = null;
        parent::tearDown();
    }

    private function episodeOnDeadHost()
    {
        $content = Content::create(['title' => 'Geats', 'slug' => 'geats', 'type' => 'series', 'is_published' => true,
            'source' => 'animeruka', 'source_key' => 'anime/geats']);
        $season = $content->seasons()->create(['number' => 1, 'title' => 'ซีซั่น 1']);
        $episode = $content->episodes()->create(['season_id' => $season->id, 'number' => 1, 'title' => 'ตอนที่ 1',
            'source' => 'animeruka', 'source_ref' => 'ep/geats-1']);
        Cache::put("ep_raw:{$episode->id}", [
            'kind' => RemoteStream::KIND_HLS, 'url' => self::DEAD, 'referer' => null,
            'source' => 'animeruka', 'key' => 'anime/geats', 'ref' => 'ep/geats-1', 'primary' => true,
        ], now()->addMinutes(10));
        Http::fake(['cdn10.maimestorage.test/*' => Http::failedConnection()]);

        return $episode;
    }

    public function test_a_host_that_no_longer_resolves_is_benched_and_its_link_rotated_past(): void
    {
        DeadHosts::$resolver = fn () => true;   // our resolver answers for everyone else
        $episode = $this->episodeOnDeadHost();

        $this->get(route('stream.manifest', $episode).'?t='.StreamController::token($episode))->assertStatus(504);

        $this->assertTrue(DeadHosts::isBenched(self::DEAD));
        $this->assertNull(Cache::get("ep_raw:{$episode->id}"), 'the dead resolution is dropped so the next request rotates on');
        $this->assertNull($episode->content->fresh()->suspended_at, 'a DNS failure never counts toward auto-suspend by itself');
        $this->assertFalse(DeadHosts::isLongDead(self::DEAD), 'a fresh bench is still treated as a possible blip');
    }

    public function test_nothing_is_benched_while_our_own_resolver_is_down(): void
    {
        DeadHosts::$resolver = fn () => false;
        $episode = $this->episodeOnDeadHost();

        $this->get(route('stream.manifest', $episode).'?t='.StreamController::token($episode))->assertStatus(504);

        $this->assertFalse(DeadHosts::isBenched(self::DEAD));
        $this->assertNotNull(Cache::get("ep_raw:{$episode->id}"));
    }

    public function test_a_host_dark_for_hours_counts_as_dead_and_one_that_answers_is_forgiven(): void
    {
        DeadHosts::$resolver = fn () => true;

        $this->assertTrue(DeadHosts::noteDnsFailure(self::DEAD));
        $this->travel(7)->hours();
        Cache::forget('stream_dns:control_ok');
        DeadHosts::noteDnsFailure(self::DEAD);   // the next viewer after the bench expired: still dark
        $this->assertTrue(DeadHosts::isLongDead(self::DEAD));

        DeadHosts::noteAlive(self::DEAD);
        $this->assertFalse(DeadHosts::isBenched(self::DEAD));
        $this->assertFalse(DeadHosts::isLongDead(self::DEAD));
    }

    public function test_only_resolver_errors_count(): void
    {
        $this->assertTrue(DeadHosts::isDnsFailure(new \RuntimeException('cURL error 6: Could not resolve host: x.test')));
        $this->assertFalse(DeadHosts::isDnsFailure(new \RuntimeException('cURL error 28: Operation timed out')));
        $this->assertFalse(DeadHosts::isDnsFailure(new \RuntimeException('cURL error 7: Failed to connect')));
    }
}
