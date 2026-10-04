<?php

namespace Tests\Feature;

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
}
