<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Stream hosts whose DNS has stopped answering. A connection error is normally transient — an outage
 * must never mass-unpublish the catalogue — but a domain that has been dropped (animemami's
 * maimestorage.xyz, 2026-11) fails the same way forever, and every viewer paid the resolver's full
 * timeout before the player gave up. One confirmed failure benches the host for BENCH seconds, so the
 * rotation skips straight to the next link; the first viewer after the bench expires re-tests it.
 *
 * "Confirmed" means a control name resolves at the same moment: if our own resolver is down, nothing is
 * benched. Only a host that has been dark for LONG_DEAD counts as a definitive failure toward a title's
 * death ([MirrorRotation]); before that a benched link is skipped but still treated as transient.
 */
class DeadHosts
{
    private const BENCH = 1800;

    /** How long a host must stay unresolvable before its links count as dead, not just slow. */
    private const LONG_DEAD = 6 * 3600;

    private const CONTROL_HOST = 'cloudflare.com';

    /** Test seam: resolves a host name → whether it answered. */
    public static ?\Closure $resolver = null;

    /** Did this exception come from the resolver ("Could not resolve host"), not the server? */
    public static function isDnsFailure(\Throwable $e): bool
    {
        return (bool) preg_match('~cURL error 6\b|Could not resolve host~i', $e->getMessage());
    }

    /**
     * $url's host just failed to resolve. Bench it when our resolver is otherwise healthy.
     * Returns true when the host is now benched.
     */
    public static function noteDnsFailure(string $url): bool
    {
        $host = self::host($url);
        if ($host === null || ! self::resolverHealthy()) {
            return false;
        }

        // Keeps the FIRST failure while the host stays dark (each failure renews the window).
        Cache::put(self::sinceKey($host), Cache::get(self::sinceKey($host)) ?? now()->getTimestamp(), now()->addDays(2));
        if (Cache::add(self::benchKey($host), 1, self::BENCH)) {
            Log::warning('stream: host does not resolve — benched', ['host' => $host]);
        }

        return true;
    }

    /** The host served a playlist: forget any history of it being dark. */
    public static function noteAlive(string $url): void
    {
        $host = self::host($url);
        if ($host !== null) {
            Cache::forget(self::benchKey($host));
            Cache::forget(self::sinceKey($host));
        }
    }

    public static function isBenched(string $url): bool
    {
        $host = self::host($url);

        return $host !== null && Cache::has(self::benchKey($host));
    }

    /** Benched, and unresolvable for long enough that it is a dropped domain, not a blip. */
    public static function isLongDead(string $url): bool
    {
        $host = self::host($url);
        if ($host === null || ! Cache::has(self::benchKey($host))) {
            return false;
        }
        $since = Cache::get(self::sinceKey($host));

        return is_int($since) && now()->getTimestamp() - $since >= self::LONG_DEAD;
    }

    private static function resolverHealthy(): bool
    {
        return (bool) Cache::remember('stream_dns:control_ok', 60, fn () => self::resolves(self::CONTROL_HOST));
    }

    private static function resolves(string $host): bool
    {
        if (self::$resolver !== null) {
            return (bool) (self::$resolver)($host);
        }

        return gethostbyname($host) !== $host;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private static function benchKey(string $host): string
    {
        return "stream_host_dead:{$host}";
    }

    private static function sinceKey(string $host): string
    {
        return "stream_host_dead_since:{$host}";
    }
}
