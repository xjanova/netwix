<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks whether the Hive Download / NetwixSync agent is connected. The agent "pings" every time
 * it polls the ingest worklist or uploads a file, so a recent ping means it's running and able to
 * download. NetWix uses this to gate the Import action — no downloader connected, no importing.
 */
class IngestAgent
{
    private const KEY = 'netwix:ingest_agent:last_seen';
    private const THRESHOLD_MINUTES = 5;

    public static function ping(): void
    {
        Cache::put(self::KEY, now()->timestamp, now()->addDay());
    }

    public static function lastSeen(): ?Carbon
    {
        $ts = Cache::get(self::KEY);

        return $ts ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    public static function connected(): bool
    {
        $seen = self::lastSeen();

        return $seen !== null && $seen->greaterThan(now()->subMinutes(self::THRESHOLD_MINUTES));
    }

    /** @return array{connected:bool,last_seen:?Carbon} */
    public static function status(): array
    {
        return ['connected' => self::connected(), 'last_seen' => self::lastSeen()];
    }
}
