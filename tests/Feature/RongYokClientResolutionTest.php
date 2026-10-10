<?php

namespace Tests\Feature;

use App\Models\AppToken;
use App\Models\Content;
use App\Models\ContentMirror;
use App\Models\Setting;
use App\Models\User;
use App\Support\RongYokClientResolver;
use App\Support\RongYokProxyPool;
use App\Support\RongYokTransport;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RongYokClientResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.rongyok.client_fallback' => true, 'services.rongyok.relay_url' => 'https://home.example', 'services.rongyok.proxy_url' => null]);
        Http::preventStrayRequests();
    }

    private function episode(array $content = [])
    {
        $c = Content::create($content + ['title' => 'เรื่อง', 'slug' => 'test', 'source' => 'rongyok', 'source_key' => '8207',
            'type' => 'vertical', 'maturity' => '15+', 'is_published' => true]);

        return $c->episodes()->create(['number' => 2, 'title' => 'ตอน 2', 'source' => 'rongyok', 'source_ref' => '2']);
    }

    private function url(): string
    {
        return 'https://cdn.discordapp.com/attachments/1/2/2.mp4?ex='.dechex(time() + 86400).'&is=abc&hm=def';
    }

    public function test_proxy_failure_offers_fixed_client_descriptor_without_unpublishing(): void
    {
        Setting::write('rongyok_proxy_url', 'http://proxy.example:8080');
        Http::fake(['rongyok.com/*' => Http::response('blocked', 403)]);
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)
            ->assertJsonPath('data.client_resolve.series_id', '8207')->assertJsonPath('data.client_resolve.episode', '2')
            ->assertJsonMissing(['proxy_url'])->assertJsonPath('data.ready', false);
        $this->assertTrue($ep->content->fresh()->is_published);
        $this->assertFalse(Cache::has('death:confirm:'.$ep->content_id));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'home.example'));
    }

    public function test_server_success_does_not_ask_client(): void
    {
        Setting::write('rongyok_proxy_url', 'http://proxy.example:8080');
        Http::fake(['rongyok.com/watch/watch.js' => Http::response(''), 'rongyok.com/watch/playseries.php*' => Http::response(['ok' => true, 'video_url' => $this->url()])]);
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk()->assertJsonPath('data.ready', true)
            ->assertJsonMissingPath('data.client_resolve');
    }

    public function test_failed_free_proxy_uses_next_verified_proxy_in_the_same_playback_request(): void
    {
        config(['services.rongyok.free_proxy_auto' => true]);
        Cache::put('rongyok:proxy:healthy', ['http://8.8.8.8:443', 'http://1.1.1.1:443'], 900);
        Cache::put('rongyok:video_endpoint', 'playseries.php', 3600);
        $selected = [];
        Http::fake(function () use (&$selected) {
            $selected[] = RongYokClientResolver::proxyUrl();

            return count($selected) === 1
                ? Http::failedConnection()
                : Http::response(['ok' => true, 'video_url' => $this->url()]);
        });
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk()
            ->assertJsonPath('data.ready', true)->assertJsonPath('data.url', $this->url())
            ->assertJsonMissingPath('data.client_resolve');
        $this->assertSame(['http://8.8.8.8:443', 'http://1.1.1.1:443'], $selected);
        $this->assertTrue($ep->content->fresh()->is_published);
    }

    public function test_exhausted_free_pool_defers_without_retrying_the_blocked_server_connection(): void
    {
        config(['services.rongyok.free_proxy_auto' => true]);
        Cache::put('rongyok:proxy:healthy', ['http://8.8.8.8:443', 'http://1.1.1.1:443'], 900);
        Cache::put('rongyok:video_endpoint', 'playseries.php', 3600);
        Http::fake(['rongyok.com/watch/playseries.php*' => Http::response('blocked', 403)]);
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)
            ->assertJsonPath('data.client_resolve.series_id', '8207');
        Http::assertSentCount(2); // each verified proxy once; no direct endpoint discovery
        $this->assertSame('', RongYokClientResolver::proxyUrl());
        $this->assertTrue($ep->content->fresh()->is_published);
    }

    public function test_removed_episode_does_not_consume_any_verified_proxy(): void
    {
        config(['services.rongyok.free_proxy_auto' => true]);
        Cache::put('rongyok:proxy:healthy', ['http://8.8.8.8:443', 'http://1.1.1.1:443'], 900);
        Cache::put('rongyok:video_endpoint', 'playseries.php', 3600);
        Http::fake([
            'rongyok.com/watch/playseries.php*' => Http::response(['ok' => false, 'error' => 'not_found'], 404),
            'rongyok.com/watch/watch.js' => Http::response('fetch(`/watch/playseries.php?series_id=1`)'),
        ]);
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202);
        $this->assertSame('http://8.8.8.8:443', RongYokClientResolver::proxyUrl());
        Http::assertSentCount(2); // one missing episode, one endpoint check; no proxy rotation
        RongYokProxyPool::failed('http://8.8.8.8:443');
        $this->assertSame('http://1.1.1.1:443', RongYokClientResolver::proxyUrl());
    }

    public function test_unpublished_and_paywalled_content_never_exposes_client_descriptor(): void
    {
        $ep = $this->episode(['is_published' => false]);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertNotFound()->assertJsonMissingPath('data.client_resolve');
        $ep->content->update(['is_published' => true, 'maturity' => '20+']);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertForbidden()->assertJsonPath('data.error', 'pro_required')
            ->assertJsonMissingPath('data.client_resolve');
    }

    public function test_vip_gate_does_not_offer_client_resolution(): void
    {
        $ep = $this->episode(['is_vip' => true]);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertForbidden()->assertJsonPath('data.error', 'vip_required')
            ->assertJsonMissingPath('data.client_resolve');
    }

    public function test_stored_video_short_circuits_client_fallback(): void
    {
        $ep = $this->episode();
        $ep->update(['video_url' => 'https://netwix.online/storage/2.mp4']);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk()->assertJsonMissingPath('data.client_resolve');
        Http::assertNothingSent();
    }

    public function test_proxy_applies_only_to_source_host_and_takes_precedence_over_home_relay(): void
    {
        Setting::write('rongyok_proxy_url', 'http://user:secret@proxy.example:8080');
        $seen = [];
        $handler = function ($r, $options) use (&$seen) {
            $seen[] = ['host' => $r->getUri()->getHost(), 'proxy' => $options['proxy'] ?? null, 'key' => $r->getHeaderLine('X-Relay-Key')];

            return Create::promiseFor(new Response(200, [], 'ok'));
        };
        // Clear the fake for this request so the mock Guzzle handler, rather than a network, is used.
        Http::swap(new Factory);
        $req = RongYokTransport::apply(Http::withOptions([])->setHandler($handler));
        $req->get('https://rongyok.com/watch/');
        $req->get('https://cdn.discordapp.com/attachments/1/2/2.mp4');
        $this->assertSame('rongyok.com', $seen[0]['host']);
        $this->assertSame('http://user:secret@proxy.example:8080', $seen[0]['proxy']);
        $this->assertSame('', $seen[0]['key']);
        $this->assertNotSame('http://user:secret@proxy.example:8080', $seen[1]['proxy']);
    }

    public function test_without_proxy_resolution_uses_device_without_a_server_request(): void
    {
        $ep = $this->episode();
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202)
            ->assertJsonPath('data.client_resolve.series_id', '8207');
        Http::assertNothingSent();
        $this->assertTrue($ep->content->fresh()->is_published);
    }

    public function test_device_resolution_does_not_mark_an_alternate_link_as_failed(): void
    {
        $ep = $this->episode();
        $mirror = ContentMirror::create(['content_id' => $ep->content_id, 'source' => 'rongyok', 'source_key' => '8300']);
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202);
        $this->assertSame(0, $mirror->fresh()->fail_streak);
        $this->assertNull($mirror->fresh()->last_failed_at);
        Http::assertNothingSent();
    }

    public function test_admin_phone_pairing_checks_owner_validates_cdn_and_reuses_verified_result(): void
    {
        $ep = $this->episode();
        $admin = User::factory()->create(['role' => 'admin']);
        // A verified assist must work even after the original source entered rotation cooldown.
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertStatus(202);
        $created = $this->actingAs($admin)->postJson(route('admin.resolve-assist.create', $ep))->assertOk();
        $code = $created->json('code');
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->getJson(route('admin.resolve-assist.status', $code))->assertForbidden();
        $token = AppToken::issue($admin);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/app/resolve-assist/'.$code)->assertOk()
            ->assertJsonPath('data.client_resolve.series_id', '8207');
        $this->postJson('/api/app/resolve-assist/'.$code, ['video_url' => 'https://127.0.0.1/private'])->assertStatus(422);
        $this->postJson('/api/app/resolve-assist/'.$code, ['video_url' => str_replace('cdn.discordapp.com', 'cdn.discordapp.com.evil.test', $this->url())])->assertStatus(422);
        Http::fake(['cdn.discordapp.com/*' => Http::response("\x00\x00\x00\x18ftypisom".str_repeat('x', 100), 206, ['Content-Type' => 'video/mp4'])]);
        $this->postJson('/api/app/resolve-assist/'.$code, ['video_url' => $this->url()])->assertOk();
        $this->postJson('/api/app/resolve-assist/'.$code, ['video_url' => $this->url()])->assertStatus(409);
        $this->assertSame($this->url(), RongYokClientResolver::cached('8207', '2')?->url);
        $this->assertNull(RongYokClientResolver::cached('8207', '3'));
        $this->getJson('/api/app/episodes/'.$ep->id.'/source')->assertOk()->assertJsonPath('data.url', $this->url());
    }

    public function test_non_admin_cannot_pair_and_expired_codes_cannot_be_used(): void
    {
        $ep = $this->episode();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('admin.resolve-assist.create', $ep))->assertForbidden();
        $token = AppToken::issue($user);
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/app/resolve-assist/'.str_repeat('A', 16))->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withoutHeader('Authorization')->actingAs($admin);
        $code = $this->postJson(route('admin.resolve-assist.create', $ep))->json('code');
        $this->travel(6)->minutes();
        $this->getJson(route('admin.resolve-assist.status', $code))->assertStatus(410);
    }

    public function test_proxy_secret_is_encrypted_and_invalid_input_is_not_flashed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $proxy = 'socks5h://user:secret@proxy.example:1080';
        $this->actingAs($admin)->put(route('admin.settings.update'), ['rongyok_proxy_url' => $proxy, 'rongyok_access_present' => 1, 'rongyok_client_fallback' => 1])->assertRedirect();
        $this->assertSame($proxy, RongYokClientResolver::proxyUrl());
        $this->assertNotSame($proxy, Setting::find('rongyok_proxy_url')->value);
        $this->put(route('admin.settings.update'), ['rongyok_proxy_url' => ''])->assertRedirect();
        $this->assertSame($proxy, RongYokClientResolver::proxyUrl());
        $this->put(route('admin.settings.update'), ['rongyok_proxy_url' => 'ftp://user:secret@host'])->assertSessionHasErrors('rongyok_proxy_url');
        $this->assertNull(session('_old_input.rongyok_proxy_url'));
    }
}
