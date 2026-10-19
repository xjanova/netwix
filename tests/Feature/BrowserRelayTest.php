<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Support\BrowserRelay;
use App\Support\RongYokClientResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserRelayTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'brainx-test-token-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.rongyok.browser_relay_enabled' => true, 'services.rongyok.browser_relay_token' => $this->token,
            'services.rongyok.client_fallback' => false, 'services.rongyok.free_proxy_auto' => false, 'services.rongyok.proxy_url' => null]);
        Http::preventStrayRequests();
        $this->beat();
    }

    /** The worker claim heartbeat: without a polling worker nothing is queued. */
    private function beat(): void
    {
        Cache::put('browser-relay:heartbeat', now()->timestamp, 300);
    }

    private function mp4(): void
    {
        // A fresh body per request: the range check reads (and exhausts) the response stream.
        Http::fake(['cdn.discordapp.com/*' => fn () => Http::response("\x00\x00\x00\x18ftypisom".str_repeat('x', 100), 206, ['Content-Type' => 'video/mp4'])]);
    }

    private function episode(array $attributes = [])
    {
        $content = Content::create($attributes + ['title' => 'Relay test', 'slug' => 'relay-'.bin2hex(random_bytes(4)),
            'source' => 'rongyok', 'source_key' => '8457', 'type' => 'vertical', 'maturity' => '15+', 'is_published' => true]);

        return $content->episodes()->create(['number' => 51, 'title' => 'Episode 51', 'source' => 'rongyok', 'source_ref' => '51']);
    }

    private function claim(): ?array
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/browser-relay/jobs/claim')->assertOk()->json('job');
    }

    private function video(string $ep = '51'): string
    {
        return 'https://cdn.discordapp.com/attachments/123/456/ep'.$ep.'.mp4?ex='.dechex(time() + 86400).'&is=abc&hm=def';
    }

    public function test_requested_miss_is_deduplicated_and_only_one_worker_can_claim_it(): void
    {
        $ep = $this->episode();
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)
                ->assertJsonPath('data.relay.state', 'pending')->assertJsonMissingPath('data.client_resolve');
        }
        $this->assertDatabaseCount('browser_relay_jobs', 1);
        $this->assertTrue($ep->content->fresh()->is_published);
        $job = $this->claim();
        $this->assertSame('8457', $job['series_id']);
        $this->assertSame('51', $job['episode']);
        $this->assertNotSame($job['lease_token'], DB::table('browser_relay_jobs')->value('lease_token'));
        $this->assertNull($this->claim());
        Http::assertNothingSent();
    }

    public function test_completed_link_is_shared_and_survives_beyond_one_hour(): void
    {
        $ep = $this->episode();
        BrowserRelay::enqueue($ep);
        $job = $this->claim();
        $url = $this->video();
        Http::fake(['cdn.discordapp.com/*' => Http::response("\x00\x00\x00\x18ftypisom".str_repeat('x', 100), 206, ['Content-Type' => 'video/mp4'])]);
        $this->postJson('/api/browser-relay/jobs/'.$job['id'].'/complete', ['lease_token' => $job['lease_token'], 'video_url' => $url])->assertOk();
        $this->withoutHeader('Authorization');
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk()->assertJsonPath('data.url', $url);
        $this->travel(2)->hours();
        $this->assertSame($url, RongYokClientResolver::cached('8457', '51')?->url);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk()->assertJsonPath('data.url', $url);
        $this->assertDatabaseCount('browser_relay_jobs', 1);
        $this->assertDatabaseHas('browser_relay_jobs', ['state' => 'ready', 'attempts' => 1]);
        // A viewer whose cache read raced completion must not create another browser job.
        BrowserRelay::enqueue($ep);
        $this->assertDatabaseHas('browser_relay_jobs', ['state' => 'ready', 'attempts' => 1]);
        RongYokClientResolver::forget($ep);
        $this->assertNull(BrowserRelay::enqueue($ep), 'an offline worker gets no queued work');
        $this->beat();
        BrowserRelay::enqueue($ep);
        $this->assertDatabaseHas('browser_relay_jobs', ['state' => 'queued', 'attempts' => 0]);
    }

    public function test_old_worker_cannot_submit_after_its_lease_is_reassigned(): void
    {
        BrowserRelay::enqueue($this->episode());
        $old = $this->claim();
        $this->travel(BrowserRelay::LEASE_SECONDS + 1)->seconds();
        $new = $this->claim();
        $this->assertNotSame($old['lease_token'], $new['lease_token']);
        $this->postJson('/api/browser-relay/jobs/'.$old['id'].'/complete', ['lease_token' => $old['lease_token'], 'video_url' => $this->video()])->assertStatus(409);
        $this->postJson('/api/browser-relay/jobs/'.$old['id'].'/fail', ['lease_token' => $old['lease_token'], 'error' => 'timeout'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_failed_work_is_bounded_and_only_a_new_viewer_request_restarts_after_cooldown(): void
    {
        $ep = $this->episode();
        BrowserRelay::enqueue($ep);
        for ($i = 0; $i < 3; $i++) {
            $job = $this->claim();
            $this->postJson('/api/browser-relay/jobs/'.$job['id'].'/fail', ['lease_token' => $job['lease_token'], 'error' => 'source_blocked'])->assertOk();
            if ($i < 2) {
                $this->travel(61)->seconds();
            }
        }
        $this->assertDatabaseHas('browser_relay_jobs', ['state' => 'failed', 'attempts' => 3]);
        $this->assertSame('retry_later', BrowserRelay::enqueue($ep)['state']);
        $this->assertNull($this->claim());
        $this->travel(601)->seconds();
        $this->assertNull($this->claim());
        $this->beat();
        $this->assertSame('pending', BrowserRelay::enqueue($ep)['state']);
        $this->assertNotNull($this->claim());
    }

    public function test_worker_rejects_unsigned_or_private_urls_before_any_fetch(): void
    {
        BrowserRelay::enqueue($this->episode());
        $job = $this->claim();
        foreach (['https://127.0.0.1/private.mp4', str_replace('cdn.discordapp.com', 'cdn.discordapp.com.evil.test', $this->video())] as $url) {
            $this->postJson('/api/browser-relay/jobs/'.$job['id'].'/complete', ['lease_token' => $job['lease_token'], 'video_url' => $url])->assertStatus(422);
        }
        $this->assertNull(RongYokClientResolver::cached('8457', '51'));
        Http::assertNothingSent();
    }

    public function test_ready_and_unauthorized_episodes_never_enqueue(): void
    {
        $ep = $this->episode();
        $ep->update(['video_url' => 'https://cdn.example.test/stored.mp4']);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk();
        $locked = $this->episode(['is_vip' => true, 'source_key' => '8458']);
        $this->getJson('/api/app/episodes/'.$locked->id.'/source')->assertForbidden();
        $hidden = $this->episode(['is_published' => false, 'source_key' => '8459']);
        $this->getJson('/api/app/episodes/'.$hidden->id.'/source')->assertNotFound();
        $this->assertDatabaseCount('browser_relay_jobs', 0);
    }

    public function test_worker_credentials_are_required_and_disabled_relay_is_inert(): void
    {
        $this->postJson('/api/browser-relay/jobs/claim')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer wrong')->postJson('/api/browser-relay/jobs/claim')->assertUnauthorized();
        config(['services.rongyok.browser_relay_enabled' => false]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/browser-relay/jobs/claim')->assertNotFound();
        $this->assertNull(BrowserRelay::enqueue($this->episode()));
        $this->assertDatabaseCount('browser_relay_jobs', 0);
    }

    public function test_stale_requests_and_unpublished_titles_are_not_opened(): void
    {
        $ep = $this->episode();
        BrowserRelay::enqueue($ep);
        $this->travel(11)->minutes();
        $this->assertNull($this->claim());
        BrowserRelay::enqueue($ep);
        $ep->content->update(['is_published' => false]);
        $this->assertNull($this->claim());
        $this->assertDatabaseHas('browser_relay_jobs', ['last_error' => 'content_unavailable']);
    }

    public function test_rongyok_mirror_of_another_source_is_relayed_too(): void
    {
        $content = Content::create(['title' => 'Mirror test', 'slug' => 'mirror-'.bin2hex(random_bytes(4)),
            'source' => 'other-site', 'source_key' => 'abc', 'type' => 'vertical', 'maturity' => '15+', 'is_published' => true]);
        $content->mirrors()->create(['source' => 'rongyok', 'source_key' => '9001']);
        $ep = $content->episodes()->create(['number' => 3, 'title' => 'Episode 3', 'source' => 'other-site', 'source_ref' => '3']);
        $this->assertSame(['source' => 'rongyok', 'series_id' => '9001', 'episode' => '3'], BrowserRelay::descriptor($ep));
        BrowserRelay::enqueue($ep);
        $job = $this->claim();
        $this->assertSame(['9001', '3'], [$job['series_id'], $job['episode']]);
    }

    public function test_next_episodes_are_prefetched_behind_viewer_requests(): void
    {
        $ep = $this->episode();
        foreach ([52, 53, 54] as $n) {
            $ep->content->episodes()->create(['number' => $n, 'title' => 'Episode '.$n, 'source' => 'rongyok', 'source_ref' => (string) $n]);
        }
        $other = $this->episode(['source_key' => '8460']);
        BrowserRelay::enqueue($ep);
        $this->travel(1)->seconds();
        BrowserRelay::enqueue($other);
        $this->assertDatabaseCount('browser_relay_jobs', 4);
        $this->assertDatabaseHas('browser_relay_jobs', ['episode' => '52', 'priority' => BrowserRelay::PRIORITY_PREFETCH]);
        $this->assertDatabaseMissing('browser_relay_jobs', ['episode' => '54']);
        // Both viewer requests (even the later one) come before the prefetched episodes.
        $claimed = array_map(fn () => $this->claim(), range(1, 4));
        $this->assertSame(['8457:51', '8460:51', '8457:52', '8457:53'],
            array_map(fn ($j) => $j['series_id'].':'.$j['episode'], $claimed));
    }

    public function test_viewer_request_promotes_a_prefetched_episode(): void
    {
        $ep = $this->episode();
        $next = $ep->content->episodes()->create(['number' => 52, 'title' => 'Episode 52', 'source' => 'rongyok', 'source_ref' => '52']);
        BrowserRelay::enqueue($next, BrowserRelay::PRIORITY_PREFETCH);
        $this->assertDatabaseHas('browser_relay_jobs', ['episode_id' => $next->id, 'priority' => BrowserRelay::PRIORITY_PREFETCH]);
        BrowserRelay::enqueue($next);
        $this->assertDatabaseHas('browser_relay_jobs', ['episode_id' => $next->id, 'priority' => BrowserRelay::PRIORITY_VIEWER]);
    }

    public function test_links_for_other_episodes_from_the_same_page_are_kept(): void
    {
        $ep = $this->episode();
        $ep->content->episodes()->create(['number' => 52, 'title' => 'Episode 52', 'source' => 'rongyok', 'source_ref' => '52']);
        BrowserRelay::enqueue($ep);
        $job = $this->claim();
        $this->mp4();
        $this->postJson('/api/browser-relay/jobs/'.$job['id'].'/complete', ['lease_token' => $job['lease_token'], 'video_url' => $this->video(),
            'extras' => [['episode' => '52', 'video_url' => $this->video('52')], ['episode' => '51', 'video_url' => $this->video('99')]]])
            ->assertOk()->assertJsonPath('extras_accepted', 1);
        $this->assertSame($this->video('52'), RongYokClientResolver::cached('8457', '52')?->url);
        $this->assertSame($this->video(), RongYokClientResolver::cached('8457', '51')?->url);
        $this->assertDatabaseHas('browser_relay_jobs', ['episode' => '52', 'state' => 'ready']);
        $this->assertNull($this->claim());
    }

    public function test_malformed_extras_are_rejected(): void
    {
        BrowserRelay::enqueue($this->episode());
        $job = $this->claim();
        $this->postJson('/api/browser-relay/jobs/'.$job['id'].'/complete', ['lease_token' => $job['lease_token'], 'video_url' => $this->video(),
            'extras' => [['episode' => '../1', 'video_url' => $this->video()]]])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_polls_while_the_browser_works_do_not_walk_the_rotation_again(): void
    {
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)->assertJsonPath('data.relay.state', 'pending');
        $walks = 0;
        Http::fake(function () use (&$walks) {
            $walks++;

            return Http::response('', 500);
        });
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)->assertJsonPath('data.relay.state', 'pending');
        }
        $this->assertSame(0, $walks);
        $this->assertDatabaseCount('browser_relay_jobs', 1);
    }

    public function test_offline_worker_leaves_playback_on_the_existing_paths(): void
    {
        Cache::forget('browser-relay:heartbeat');
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)->assertJsonMissingPath('data.relay');
        $this->assertDatabaseCount('browser_relay_jobs', 0);
    }
}
