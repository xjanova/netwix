<?php

namespace App\Support;

use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Cache;

/** Last-known-good DNS for HTTPS segments; never replaces the hostname or TLS verification. */
class StreamDns
{
    private const RETENTION_DAYS = 7;

    private const RETRY_DNS_SECONDS = 300;

    public static function options(string $url): array
    {
        $host = self::host($url);
        if ($host === null) {
            return [];
        }

        $ip = Cache::has("stream_dns:fallback:{$host}") ? self::knownIp($host) : null;
        $options = [];
        if ($ip !== null) {
            $address = str_contains($ip, ':') ? "[{$ip}]" : $ip;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:443:{$address}"]];
        }

        $options['on_stats'] = function (TransferStats $stats) use ($url, $host, $ip): void {
            // Pinned connections must NOT extend the lifetime of an old DNS answer. Redirects to
            // another host must not teach us that host's IP as the original host's address either.
            if ($ip === null && $stats->hasResponse()
                && strtolower($stats->getEffectiveUri()->getHost()) === $host) {
                self::remember($url, (string) ($stats->getHandlerStats()['primary_ip'] ?? ''));
            }
        };

        return $options;
    }

    /** Called after a connection failure, before the existing bounded segment retry. */
    public static function activate(string $url): bool
    {
        $host = self::host($url);
        if ($host === null || self::knownIp($host) === null) {
            return false;
        }

        // add(), not put(): failures while pinned must not postpone trying normal DNS again.
        Cache::add("stream_dns:fallback:{$host}", true, self::RETRY_DNS_SECONDS);

        return true;
    }

    /** Also usable to seed a TLS-verified address during recovery from an existing DNS outage. */
    public static function remember(string $url, string $ip): bool
    {
        $host = self::host($url);
        if ($host === null || ! self::publicIp($ip)) {
            return false;
        }

        Cache::put("stream_dns:known:{$host}", $ip, now()->addDays(self::RETENTION_DAYS));
        Cache::forget("stream_dns:fallback:{$host}");

        return true;
    }

    private static function host(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['port'] ?? 443) !== 443 || empty($parts['host'])) {
            return null;
        }

        return strtolower($parts['host']);
    }

    private static function knownIp(string $host): ?string
    {
        $ip = Cache::get("stream_dns:known:{$host}");

        return is_string($ip) && self::publicIp($ip) ? $ip : null;
    }

    private static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
