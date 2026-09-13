<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Per-SOURCE health, as opposed to per-title health ([PlaybackHealth]). A source can fail as a whole —
 * 24-hdx put a Cloudflare challenge on its player ajax on 2026-07-28 and all ~6,500 of its titles
 * stopped resolving in the same second; anime108 simply died; 9nung migrated its player. In every case
 * nothing was wrong with any individual title, and the per-title machinery is the wrong instrument:
 * it only learns from viewers hitting failures, and left alone it would unpublish a whole catalogue
 * one title at a time.
 *
 * [App\Console\Commands\SourceCanaryCommand] probes a few known titles per source on a schedule and
 * records the verdict here. Two things read it:
 *  - [PlaybackHealth] stops auto-suspending titles of a source that is wholly down (the outage is not
 *    the title's fault, and the titles must still be there when the source comes back),
 *  - the admin dashboard, which shows the outage so it's noticed in hours rather than whenever
 *    someone happens to browse.
 *
 * State lives in one `source_health` setting (a small JSON map) — no migration, and it survives a
 * cache flush, which matters because the auto-suspend brake hangs off it.
 */
class SourceHealth
{
    private const KEY = 'source_health';

    /** Verdicts are re-read from the DB at most this often; the canary clears it when it writes. */
    private const CACHE_TTL = 60;

    /**
     * Record one canary run.
     *
     * @param  int  $ok  titles that resolved
     * @param  int  $tried  titles probed
     */
    public static function record(string $source, int $ok, int $tried): void
    {
        $all = self::all();
        $was = $all[$source] ?? [];
        $down = $tried > 0 && $ok === 0;

        $all[$source] = [
            'ok' => $ok,
            'tried' => $tried,
            'down' => $down,
            // Keep the ORIGINAL down_since across consecutive failures so the dashboard can say how
            // long this has been going on, not just that the last probe failed.
            'down_since' => $down ? ($was['down_since'] ?? now()->toIso8601String()) : null,
            'checked_at' => now()->toIso8601String(),
        ];

        Setting::write(self::KEY, json_encode($all, JSON_UNESCAPED_UNICODE));
        Cache::forget(self::KEY);

        // Close the loop: the owner was told it went down, so tell them it came back — otherwise the
        // last word on their phone is an outage that ended hours ago.
        if (! empty($was['down']) && ! $down) {
            self::announceRecovery($source, (string) ($was['down_since'] ?? ''), $ok, $tried);
            // A fresh outage after a recovery is news again, not a repeat inside the 6h cool-off.
            AdminAlerts::forgetThrottle('source-down:'.$source);
        }
    }

    private static function announceRecovery(string $source, string $since, int $ok, int $tried): void
    {
        $lasted = '—';
        try {
            if ($since !== '') {
                $mins = (int) \Illuminate\Support\Carbon::parse($since)->diffInMinutes(now());
                $lasted = $mins >= 1440 ? intdiv($mins, 1440).' วัน '.intdiv($mins % 1440, 60).' ชม.' : intdiv($mins, 60).' ชม. '.($mins % 60).' นาที';
            }
        } catch (\Throwable) {
        }

        AdminAlerts::send(new Alerts\Alert(
            key: 'source-up:'.$source,
            level: Alerts\Alert::OK,
            title: "แหล่ง \"{$source}\" กลับมาดึงลิ้งค์ได้แล้ว",
            body: 'ระบบตรวจรอบล่าสุดเล่นได้ตามปกติ และกลับมาหยุดเผยแพร่อัตโนมัติเฉพาะเรื่องที่เสียจริงเหมือนเดิม',
            facts: ['ผลตรวจล่าสุด' => "{$ok}/{$tried} เล่นได้", 'ล่มไปนาน' => $lasted],
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'sources',
        ), 60);
    }

    /** True when the last canary run couldn't resolve a single title from this source. */
    public static function isDown(string $source): bool
    {
        return (bool) (self::all()[$source]['down'] ?? false);
    }

    /** @return array<string,array<string,mixed>> source id => verdict */
    public static function all(): array
    {
        $raw = Cache::remember(self::KEY, self::CACHE_TTL, fn () => (string) Setting::get(self::KEY, ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,array<string,mixed>> only the sources currently down */
    public static function down(): array
    {
        return array_filter(self::all(), fn ($v) => ! empty($v['down']));
    }
}
