<?php

namespace App\Support;

/**
 * Helpers for invoking the static ffmpeg on the prod box POLITELY.
 *
 * NetWix shares an oversubscribed host (blockscout/Postgres/n8n/~40 domains). On 2026-07-06 an
 * unbounded cover+clip batch let libx264 grab all 24 cores per process → load 16 → the whole box
 * went 522 (see brain: "2026-07-06 INCIDENT — stacked ffmpeg cover/clip workers"). The fix is not to
 * disable the pools but to make every ffmpeg run yield: lowest CPU priority (`nice -n 19`) + idle-ish
 * IO (`ionice -c 2 -n 7`), with a per-call `-threads` cap at the call site. Then the scheduler can run
 * the full pipeline without ever starving the web/DB.
 */
class Ffmpeg
{
    /** Absolute path to the static ffmpeg binary. */
    public static function bin(): string
    {
        return (string) config('services.ffmpeg.bin', '/home/admin/bin/ffmpeg');
    }

    /**
     * Wrap an ffmpeg argv (`[$bin, '-y', …]`) with the nice/ionice prefix so it runs at idle priority.
     * Configurable via `services.ffmpeg.nice_prefix` (string like "nice -n 19", or "" to disable);
     * null = auto: applied on Linux (prod), a no-op elsewhere (Windows/mac dev where the tools are absent).
     *
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    public static function cmd(array $args): array
    {
        $prefix = config('services.ffmpeg.nice_prefix');
        if ($prefix === null) {
            $prefix = PHP_OS_FAMILY === 'Linux' ? 'nice -n 19 ionice -c 2 -n 7' : '';
        }
        if (! is_array($prefix)) {
            $prefix = array_values(array_filter(preg_split('/\s+/', trim((string) $prefix)), 'strlen'));
        }

        return array_merge($prefix, $args);
    }
}
