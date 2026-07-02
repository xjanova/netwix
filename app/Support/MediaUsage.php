<?php

namespace App\Support;

use App\Models\Episode;

/** Aggregates for mirrored media (used by the dashboard widget and the storage page). */
class MediaUsage
{
    /**
     * @return array{used:int,mirrored:int,cap_bytes:float,free:float,total:float,avg:float,per_source:array}
     */
    public static function summary(): array
    {
        $used = (int) Episode::whereNotNull('mirrored_at')->sum('file_size');
        $mirrored = (int) Episode::whereNotNull('mirrored_at')->count();
        $capBytes = (float) config('services.ingest.max_gb', 55) * 1_000_000_000;

        $path = storage_path();
        $free = (float) (@disk_free_space($path) ?: 0);
        $total = (float) (@disk_total_space($path) ?: 0);

        $perSource = Episode::whereNotNull('mirrored_at')
            ->selectRaw('source, COUNT(*) as c, COALESCE(SUM(file_size),0) as b')
            ->groupBy('source')
            ->get()
            ->map(fn ($r) => ['source' => $r->source ?: 'อื่นๆ', 'count' => (int) $r->c, 'bytes' => (int) $r->b])
            ->all();

        return [
            'used' => $used,
            'mirrored' => $mirrored,
            'cap_bytes' => $capBytes,
            'free' => $free,
            'total' => $total,
            'avg' => $mirrored ? $used / $mirrored : 0,
            'per_source' => $perSource,
        ];
    }

    public static function gb(float|int $bytes): float
    {
        return round($bytes / 1_000_000_000, 2);
    }

    public static function mb(float|int $bytes): float
    {
        return round($bytes / 1_000_000, 1);
    }
}
