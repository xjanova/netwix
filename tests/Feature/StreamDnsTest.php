<?php

namespace Tests\Feature;

use App\Support\StreamDns;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Tests\TestCase;

class StreamDnsTest extends TestCase
{
    private const URL = 'https://cdn.test/video/0.ts';

    public function test_normal_tls_transfer_teaches_an_address_for_connection_failure_recovery(): void
    {
        $normal = StreamDns::options(self::URL);
        $this->assertArrayNotHasKey('curl', $normal);
        $normal['on_stats'](new TransferStats(new Request('GET', self::URL), new Response(200), 0.1, null, ['primary_ip' => '104.26.7.53']));

        $this->assertTrue(StreamDns::activate(self::URL));
        $fallback = StreamDns::options(self::URL);
        $this->assertSame(['cdn.test:443:104.26.7.53'], $fallback['curl'][CURLOPT_RESOLVE]);
        $this->assertArrayNotHasKey('verify', $fallback);
        $this->assertArrayNotHasKey('curl', StreamDns::options('https://other.test/video/0.ts'));

        $this->travel(301)->seconds();
        $this->assertArrayNotHasKey('curl', StreamDns::options(self::URL), 'retry normal DNS after five minutes');
    }

    public function test_pinned_transfers_and_repeated_failures_never_keep_an_old_address_alive_forever(): void
    {
        StreamDns::remember(self::URL, '104.26.7.53');
        StreamDns::activate(self::URL);
        $this->travel(250)->seconds();
        StreamDns::activate(self::URL);
        $fallback = StreamDns::options(self::URL);
        $fallback['on_stats'](new TransferStats(new Request('GET', self::URL), new Response(200), 0.1, null, ['primary_ip' => '104.26.7.53']));
        $this->travel(51)->seconds();
        $this->assertArrayNotHasKey('curl', StreamDns::options(self::URL));

        $this->travel(7)->days();
        $this->assertFalse(StreamDns::activate(self::URL));
    }

    public function test_private_addresses_failed_tls_and_redirects_cannot_poison_the_fallback(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '::1', 'invalid'] as $ip) {
            $this->assertFalse(StreamDns::remember(self::URL, $ip));
        }
        $this->assertFalse(StreamDns::remember('http://cdn.test/0.ts', '104.26.7.53'));
        $this->assertFalse(StreamDns::remember('https://cdn.test:8443/0.ts', '104.26.7.53'));

        $normal = StreamDns::options(self::URL);
        $normal['on_stats'](new TransferStats(new Request('GET', self::URL), null, 0.1, 60, ['primary_ip' => '104.26.7.53']));
        $normal['on_stats'](new TransferStats(new Request('GET', 'https://other.test/0.ts'), new Response(200), 0.1, null, ['primary_ip' => '104.26.7.53']));
        $this->assertFalse(StreamDns::activate(self::URL));
    }
}
