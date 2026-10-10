<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Support\RongYokClientResolver;
use App\Support\RongYokProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RongYokProxyPoolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.rongyok.free_proxy_auto' => true, 'services.rongyok.proxy_url' => null]);
        Http::preventStrayRequests();
        foreach (['100001', '100002', '100003'] as $key) {
            $c = Content::create(['title' => 'Canary '.$key, 'slug' => 'canary-'.$key, 'source' => 'rongyok',
                'source_key' => $key, 'type' => 'vertical', 'maturity' => '15+', 'is_published' => true]);
            $c->episodes()->create(['number' => 1, 'title' => 'ตอน 1', 'source' => 'rongyok', 'source_ref' => '1']);
        }
    }

    private function candidate(string $ip): array
    {
        return ['ip' => $ip, 'port' => 443, 'protocol' => 'http', 'ssl' => true, 'last_checked' => time()];
    }

    public function test_local_network_and_malformed_proxy_destinations_are_rejected(): void
    {
        foreach (['http://127.0.0.1:443', 'http://10.0.0.1:80', 'http://169.254.169.254:80', 'http://192.168.1.2:80', 'http://8.8.8.8:65536', 'http://8.8.8.8:443/path', 'http://evil.test:443'] as $proxy) {
            $this->assertFalse(RongYokProxyPool::validProxy($proxy));
        }
        $this->assertTrue(RongYokProxyPool::validProxy('http://8.8.8.8:443'));
    }

    public function test_source_and_mp4_must_both_pass_before_a_proxy_is_selected(): void
    {
        $url = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([$this->candidate('8.8.8.8'), $this->candidate('127.0.0.1')]),
            'rongyok.com/watch/*' => Http::response(['ok' => true, 'video_url' => $url]),
            'cdn.discordapp.com/*' => Http::response("\x00\x00\x00\x18ftypisom", 206, ['Content-Type' => 'video/mp4']),
        ]);
        $this->assertSame(1, RongYokProxyPool::refresh());
        $this->assertSame('http://8.8.8.8:443', RongYokClientResolver::proxyUrl());
        RongYokProxyPool::failed('http://8.8.8.8:443');
        $this->assertSame('', RongYokProxyPool::selected());
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://rongyok.com/watch/') && ! $r->hasHeader('Authorization') && ! $r->hasHeader('X-Relay-Key'));
    }

    public function test_http_success_without_a_real_video_is_not_healthy(): void
    {
        $url = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([$this->candidate('8.8.8.8')]),
            'rongyok.com/watch/*' => Http::response(['ok' => true, 'video_url' => $url]),
            'cdn.discordapp.com/*' => Http::response('not a video', 200, ['Content-Type' => 'text/html']),
        ]);
        $this->assertSame(0, RongYokProxyPool::refresh());
        $this->assertSame('', RongYokProxyPool::selected());
    }

    public function test_disabled_pool_performs_no_network_requests(): void
    {
        config(['services.rongyok.free_proxy_auto' => false]);
        $this->assertSame(0, RongYokProxyPool::refresh());
        $this->assertSame('', RongYokProxyPool::selected());
        Http::assertNothingSent();
    }

    public function test_frequent_checks_reuse_the_provider_list_but_recheck_the_source(): void
    {
        $url = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([$this->candidate('8.8.8.8')]),
            'rongyok.com/watch/*' => Http::response(['ok' => true, 'video_url' => $url]),
            'cdn.discordapp.com/*' => fn () => Http::response("\x00\x00\x00\x18ftypisom", 206, ['Content-Type' => 'video/mp4']),
        ]);
        $this->assertSame(1, RongYokProxyPool::refresh());
        $this->travel(1)->minutes();
        $this->assertSame(1, RongYokProxyPool::refresh());
        Http::assertSentCount(5); // one provider fetch, two source checks, two short MP4 checks
        $this->travel(5)->minutes();
        $this->assertSame(1, RongYokProxyPool::refresh());
        Http::assertSentCount(8); // provider list expires independently of the minute checks
    }

    public function test_concurrent_refresh_does_not_start_another_scan_or_wait_for_it(): void
    {
        Cache::put('rongyok:proxy:healthy', ['http://8.8.8.8:443', 'http://1.1.1.1:443'], 900);
        RongYokProxyPool::failed('http://8.8.8.8:443');
        $lock = Cache::lock('rongyok:proxy:refresh', 180);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(1, RongYokProxyPool::refresh());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_identical_canary_video_is_validated_only_once_per_scan(): void
    {
        $url = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([$this->candidate('8.8.8.8'), $this->candidate('1.1.1.1')]),
            'rongyok.com/watch/*' => Http::response(['ok' => true, 'video_url' => $url]),
            'cdn.discordapp.com/*' => Http::response("\x00\x00\x00\x18ftypisom", 206, ['Content-Type' => 'video/mp4']),
        ]);
        $this->assertSame(2, RongYokProxyPool::refresh());
        Http::assertSentCount(4); // provider, two source requests, one MP4 prefix
    }

    public function test_playback_failure_during_scan_is_not_readmitted_by_the_canary(): void
    {
        Cache::put('rongyok:proxy:healthy', ['http://8.8.8.8:443'], 900);
        $url = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([]),
            'rongyok.com/watch/*' => function () use ($url) {
                RongYokProxyPool::failed('http://8.8.8.8:443');

                return Http::response(['ok' => true, 'video_url' => $url]);
            },
            'cdn.discordapp.com/*' => Http::response("\x00\x00\x00\x18ftypisom", 206, ['Content-Type' => 'video/mp4']),
        ]);
        $this->assertSame(0, RongYokProxyPool::refresh());
        $this->assertSame('', RongYokProxyPool::selected());
    }

    public function test_removed_canary_uses_another_catalogue_title_before_judging_the_proxy(): void
    {
        $url = 'https://cdn.discordapp.com/attachments/1/2/1.mp4?ex='.dechex(time() + 86400).'&is=x&hm=y';
        $checks = [];
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([$this->candidate('8.8.8.8')]),
            'rongyok.com/watch/*' => function ($request) use (&$checks, $url) {
                $checks[] = $request['series_id'];

                return count($checks) === 1 ? Http::response(['ok' => false, 'error' => 'not_found'], 404)
                    : Http::response(['ok' => true, 'video_url' => $url]);
            },
            'cdn.discordapp.com/*' => Http::response("\x00\x00\x00\x18ftypisom", 206, ['Content-Type' => 'video/mp4']),
        ]);
        $this->assertSame(1, RongYokProxyPool::refresh());
        $this->assertCount(2, array_unique($checks));
        $this->assertSame('http://8.8.8.8:443', RongYokProxyPool::selected());
        Http::assertSentCount(4);
    }

    public function test_all_canaries_missing_preserves_previous_verification_without_extending_its_expiry(): void
    {
        Cache::put('rongyok:proxy:healthy', ['http://8.8.8.8:443'], 120);
        Http::fake([
            RongYokProxyPool::LIST_URL => Http::response([]),
            'rongyok.com/watch/*' => Http::response(['ok' => false, 'error' => 'not_found'], 404),
        ]);
        $this->assertSame(1, RongYokProxyPool::refresh());
        $this->assertSame('http://8.8.8.8:443', RongYokProxyPool::selected());
        $status = Cache::get('rongyok:proxy:last_check');
        $this->assertSame('inconclusive', $status['state']);
        $this->assertSame(0, $status['verified']);
        $this->assertSame(3, $status['canaries']);
        $this->travel(3)->minutes();
        $this->assertSame('', RongYokProxyPool::selected());
    }
}
