<?php

namespace App\Support;

use App\Models\MirrorJob;
use App\Services\Import\SourceRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the download monitor shows, per source, measured rather than assumed.
 *
 * Three separate questions get three separate answers here, and the page keeps them separate on
 * screen too, because collapsing them is how a storage plan ends up being fiction:
 *
 *   1. **How much is there?**  Straight counts off contents/episodes.
 *   2. **Can we even download it?**  The source's own `isProgressive()`. Two of nine sources hand us a
 *      plain MP4; the rest are HLS and need ffmpeg to become one file, which the downloader does not
 *      do yet. A source that cannot be downloaded says so with its episode count, instead of sitting
 *      at 0% forever looking broken.
 *   3. **How big would it get?**  Only from real bytes — the average of files we have already stored,
 *      or failing that of [MirrorProbe] measurements. With neither, `avg` is null and the page prints
 *      "ยังไม่ได้วัด" plus the button that measures it. There is deliberately no fallback constant: a
 *      made-up average is exactly how the old dashboard ended up quoting invented numbers.
 */
class MirrorPlan
{
    /** Backblaze B2, the storage picked for this (cheapest that is still good for streaming). */
    public const B2_USD_PER_TB_MONTH = 6.0;

    /** Free disk we refuse to eat into, so the box never fills up because of a download run. */
    public const DISK_RESERVE_BYTES = 15_000_000_000;

    /** @return array<int,array<string,mixed>> one row per source, biggest catalogue first */
    public static function rows(): array
    {
        $catalogue = self::catalogue();
        $progress = self::progress();
        $probes = self::probeAverages();
        $jobs = MirrorJob::all()->keyBy('source');
        $registry = app(SourceRegistry::class);

        $rows = [];
        foreach ($catalogue as $key => $c) {
            $source = $registry->get($key);
            $progressive = $source?->isProgressive() ?? false;
            $p = $progress[$key] ?? ['mirrored' => 0, 'bytes' => 0];

            // Prefer the average of what we have actually stored — it is the truest number available,
            // since it went through the same download path. Probes are the stand-in until then.
            $avg = null;
            $avgFrom = null;
            $samples = 0;
            if ($p['mirrored'] >= 3 && $p['bytes'] > 0) {
                $avg = (int) round($p['bytes'] / $p['mirrored']);
                $avgFrom = 'stored';
                $samples = $p['mirrored'];
            } elseif (isset($probes[$key])) {
                $avg = $probes[$key]['avg'];
                $avgFrom = 'probe';
                $samples = $probes[$key]['samples'];
            }

            $remaining = max(0, $c['refd'] - $p['mirrored']);

            $rows[] = [
                'source' => $key,
                'label' => $source?->displayName() ?? $key,
                'known' => $source !== null,
                'titles' => $c['titles'],
                'episodes' => $c['episodes'],
                'refd' => $c['refd'],
                'mirrored' => $p['mirrored'],
                'bytes' => $p['bytes'],
                'remaining' => $remaining,
                'kind' => $progressive ? 'mp4' : 'hls',
                'can_download' => $progressive,
                'blocked_reason' => self::blockedReason($source !== null, $progressive),
                'avg' => $avg,
                'avg_from' => $avgFrom,
                'avg_samples' => $samples,
                'projected' => $avg !== null ? $avg * $c['refd'] : null,
                'projected_remaining' => $avg !== null ? $avg * $remaining : null,
                'job' => ($j = $jobs->get($key)) ? [
                    'status' => $j->status,
                    'state' => $j->displayState(),
                    'scope' => $j->scope,
                    'limit' => $j->episode_limit,
                    'done' => $j->done_count,
                    'fail' => $j->fail_count,
                    'bytes' => $j->bytes_done,
                    'last_title' => $j->last_title,
                    'last_error' => $j->last_error,
                    'worker' => $j->hasWorker(),
                    'seen' => $j->worker_seen_at?->diffForHumans(),
                ] : null,
            ];
        }

        usort($rows, fn ($a, $b) => $b['episodes'] <=> $a['episodes']);

        return $rows;
    }

    private static function blockedReason(bool $known, bool $progressive): ?string
    {
        if (! $known) {
            return 'แหล่งนี้ไม่ได้ลงทะเบียนในระบบแล้ว — ดาวน์โหลดใหม่ไม่ได้';
        }
        if (! $progressive) {
            return 'สตรีมแบบ HLS (ไฟล์ย่อยหลายพันชิ้น) — ต้องต่อ ffmpeg รวมไฟล์ก่อน ยังไม่เปิดใช้';
        }

        return null;
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function totals(array $rows): array
    {
        $sum = fn (string $k) => array_sum(array_map(fn ($r) => (int) ($r[$k] ?? 0), $rows));

        $ready = array_values(array_filter($rows, fn ($r) => $r['can_download']));
        $measured = array_values(array_filter($rows, fn ($r) => $r['projected'] !== null));
        $unmeasured = array_values(array_filter($rows, fn ($r) => $r['projected'] === null));

        $free = (float) (@disk_free_space(storage_path()) ?: 0);
        $total = (float) (@disk_total_space(storage_path()) ?: 0);

        return [
            'titles' => $sum('titles'),
            'episodes' => $sum('episodes'),
            'mirrored' => $sum('mirrored'),
            'bytes' => $sum('bytes'),
            'ready_episodes' => array_sum(array_map(fn ($r) => $r['refd'], $ready)),
            'blocked_episodes' => $sum('refd') - array_sum(array_map(fn ($r) => $r['refd'], $ready)),
            // Only sources we have really measured contribute to the projection, and the page always
            // shows how many did NOT, so an under-count can never read as a complete answer.
            'projected' => array_sum(array_map(fn ($r) => $r['projected'], $measured)),
            'projected_from' => count($measured),
            'projected_missing' => count($unmeasured),
            'projected_missing_episodes' => array_sum(array_map(fn ($r) => $r['refd'], $unmeasured)),
            'disk_free' => $free,
            'disk_total' => $total,
            'disk_usable' => max(0, $free - self::DISK_RESERVE_BYTES),
        ];
    }

    /** Monthly Backblaze B2 storage cost for a byte count (egress is free via Cloudflare). */
    public static function monthlyUsd(float|int $bytes): float
    {
        return round($bytes / 1_000_000_000_000 * self::B2_USD_PER_TB_MONTH, 2);
    }

    /**
     * Catalogue size per source. Cached — these move on import runs, not minute to minute, and the
     * monitor polls every few seconds.
     *
     * @return array<string,array{titles:int,episodes:int,refd:int}>
     */
    private static function catalogue(): array
    {
        return Cache::remember('mirror:catalogue:v1', now()->addMinutes(10), function () {
            return DB::table('contents')
                ->leftJoin('episodes', 'episodes.content_id', '=', 'contents.id')
                ->whereNotNull('contents.source')
                ->selectRaw('contents.source as src, COUNT(DISTINCT contents.id) titles, COUNT(episodes.id) eps, '
                    ."SUM(CASE WHEN episodes.source_ref IS NOT NULL AND episodes.source_ref <> '' THEN 1 ELSE 0 END) refd")
                ->groupBy('contents.source')
                ->get()
                ->mapWithKeys(fn ($r) => [$r->src => [
                    'titles' => (int) $r->titles,
                    'episodes' => (int) $r->eps,
                    'refd' => (int) $r->refd,
                ]])
                ->all();
        });
    }

    /**
     * What is stored right now. Live on every poll — this is the number the admin is watching move.
     *
     * @return array<string,array{mirrored:int,bytes:int}>
     */
    private static function progress(): array
    {
        return DB::table('episodes')
            ->join('contents', 'contents.id', '=', 'episodes.content_id')
            ->whereNotNull('episodes.mirrored_at')
            ->selectRaw('contents.source as src, COUNT(*) c, COALESCE(SUM(episodes.file_size),0) b')
            ->groupBy('contents.source')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->src => ['mirrored' => (int) $r->c, 'bytes' => (int) $r->b]])
            ->all();
    }

    /**
     * Average measured episode size per source, from successful probes only.
     *
     * @return array<string,array{avg:int,samples:int}>
     */
    private static function probeAverages(): array
    {
        return DB::table('mirror_probes')
            ->where('ok', true)
            ->whereNotNull('bytes')
            ->where('bytes', '>', 0)
            ->selectRaw('source, AVG(bytes) a, COUNT(*) n')
            ->groupBy('source')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->source => ['avg' => (int) round($r->a), 'samples' => (int) $r->n]])
            ->all();
    }
}
