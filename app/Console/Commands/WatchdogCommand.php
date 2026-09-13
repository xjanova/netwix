<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SourceTitle;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\Alerts\Redact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The quiet failures nothing else reports, checked every 5 minutes:
 *
 *  - the scheduler's own heartbeat — read back by [App\Http\Middleware\WatchScheduler] on web
 *    requests, because a dead cron cannot report itself (it was dead for 9 days once, and again
 *    until 2026-07-13, and both times nobody knew);
 *  - disk space — the mirror and preview writers each check "is there room" for themselves, but
 *    nothing warned anyone BEFORE the disk filled, and a full disk takes the whole site down;
 *  - queued jobs that failed for good — `failed_jobs` filled up with nobody reading it;
 *  - catalogues that stopped growing — a green playback canary hid a dead catalogue twice
 *    (wow-drama 21 nights, 24hdx 15 days). `max(synced_at)` per source is what catches it.
 *
 *   php artisan netwix:watchdog
 */
class WatchdogCommand extends Command
{
    protected $signature = 'netwix:watchdog';

    protected $description = 'Scheduler heartbeat + disk space + failed jobs + stale catalogue checks (alerts the admin).';

    public function handle(): int
    {
        Cache::forever('scheduler:heartbeat', now()->timestamp);

        foreach (['checkDisk', 'checkFailedJobs', 'checkFreshness'] as $check) {
            try {
                $this->{$check}();
            } catch (Throwable $e) {
                // One broken check must not silence the others.
                $this->warn("{$check}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function checkDisk(): void
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return;
        }
        $gb = $free / 1024 ** 3;
        $pct = $free / $total * 100;
        $this->line(sprintf('disk: %.1f GB free (%.1f%%)', $gb, $pct));

        // The mirror planner already keeps a 15 GB reserve; warn when we are into it.
        $level = match (true) {
            $gb < 5 || $pct < 3 => Alert::CRITICAL,
            $gb < 15 || $pct < 8 => Alert::WARNING,
            default => null,
        };
        if ($level === null) {
            AdminAlerts::forgetThrottle('disk-low:'.Alert::WARNING);
            AdminAlerts::forgetThrottle('disk-low:'.Alert::CRITICAL);

            return;
        }

        $usedPct = 100 - $pct;
        AdminAlerts::send(new Alert(
            key: 'disk-low:'.$level,
            level: $level,
            title: $level === Alert::CRITICAL ? 'ดิสก์เซิร์ฟเวอร์ใกล้เต็มมาก' : 'ดิสก์เซิร์ฟเวอร์เหลือน้อย',
            body: $level === Alert::CRITICAL
                ? 'ถ้าเต็ม เว็บจะเขียนไฟล์/ฐานข้อมูลไม่ได้และล่มทั้งเว็บ — ลบคลิป/ไฟล์มิเรอร์ที่ไม่ใช้ หรือย้ายไป R2 ด่วน'
                : 'ควรเคลียร์ไฟล์มิเรอร์/คลิปเก่า ก่อนที่งานดาวน์โหลดจะหยุดเองหรือดิสก์เต็ม',
            facts: [
                'เหลือว่าง' => number_format($gb, 1).' GB',
                'ใช้ไปแล้ว' => number_format($usedPct, 1).'%',
                'ทั้งหมด' => number_format($total / 1024 ** 3, 0).' GB',
            ],
            bars: ['ใช้แล้ว' => (int) round($usedPct), 'ว่าง' => (int) round($pct)],
            url: url('/admin/storage'),
            urlLabel: 'เปิดหน้าพื้นที่จัดเก็บ',
            category: 'system',
            barsLabel: 'สัดส่วนดิสก์ (%)',
        ), $level === Alert::CRITICAL ? 180 : 720);
    }

    /** Jobs that used up their retries since the last look, grouped by job type. */
    private function checkFailedJobs(): void
    {
        $lastSeen = Cache::get('watchdog:failed_jobs:last_id');
        $maxId = (int) DB::table('failed_jobs')->max('id');
        Cache::forever('watchdog:failed_jobs:last_id', $maxId);
        if ($lastSeen === null || $maxId <= (int) $lastSeen) {
            return;     // first run starts the clock — old history is not news
        }

        $rows = DB::table('failed_jobs')->where('id', '>', (int) $lastSeen)->orderBy('id')->limit(200)->get(['payload', 'exception', 'queue']);
        $byJob = [];
        $firstError = [];
        foreach ($rows as $row) {
            $name = class_basename((string) (json_decode((string) $row->payload, true)['displayName'] ?? 'งานไม่ทราบชื่อ'));
            $byJob[$name] = ($byJob[$name] ?? 0) + 1;
            $firstError[$name] ??= strtok((string) $row->exception, "\n") ?: '';
        }
        arsort($byJob);
        $top = array_key_first($byJob);
        $this->line('failed jobs: '.count($rows));

        AdminAlerts::send(new Alert(
            key: 'failed-jobs',
            level: Alert::WARNING,
            title: 'งานเบื้องหลังล้มเหลว '.number_format(count($rows)).' งาน',
            body: "ล้มเหลวหลังลองครบทุกครั้งแล้ว — ตัวอย่างสาเหตุ ({$top}):\n"
                .mb_substr(Redact::text($firstError[$top] ?? ''), 0, 300),
            facts: ['งานที่ล้มเหลว' => number_format(count($rows)).' งาน', 'ประเภท' => count($byJob).' แบบ'],
            bars: array_slice($byJob, 0, 6, true),
            url: url('/admin'),
            urlLabel: 'เปิดหน้าแอดมิน',
            category: 'system',
            barsLabel: 'แยกตามประเภทงาน',
        ), 180);
    }

    /**
     * Once a day: every source that auto-import should be syncing, whose newest `synced_at` is older
     * than twice its schedule allows. Playback can be perfectly healthy while this is dead.
     */
    private function checkFreshness(): void
    {
        if (! Setting::flag('auto_import_enabled', false) || ! Cache::add('watchdog:freshness:'.now()->toDateString(), 1, now()->addDay())) {
            return;
        }

        $hidden = array_filter(array_map('trim', explode(',', (string) Setting::get('hidden_sources', ''))));
        foreach ($this->scheduledSources() as $sid => $daysPerWeek) {
            if (in_array($sid, $hidden, true)) {
                continue;
            }
            // Daily → stale after 3 days; weekly → after 15. Never tighter than 3.
            $allowDays = max(3, (int) ceil(14 / max(1, $daysPerWeek)) + 1);
            $last = SourceTitle::where('source', $sid)->max('synced_at');
            if ($last !== null && now()->diffInDays($last, true) < $allowDays) {
                AdminAlerts::forgetThrottle('catalog-stale:'.$sid);

                continue;
            }

            $ago = $last !== null ? (int) floor(now()->diffInDays($last, true)).' วัน' : 'ไม่เคย';
            $this->warn("{$sid}: catalogue stale (last sync {$ago})");
            AdminAlerts::send(new Alert(
                key: 'catalog-stale:'.$sid,
                level: Alert::WARNING,
                title: "แหล่ง \"{$sid}\" ไม่มีรายชื่อเรื่องใหม่มา {$ago}",
                body: 'หนังที่นำเข้าแล้วอาจยังดูได้ปกติ (ระบบตรวจแหล่งจึงยังเขียว) แต่รายชื่อเรื่องใหม่หยุดเข้ามา '
                    .'— มักเป็นเพราะต้นทางเปลี่ยนหน้าเว็บ ปิด API หรือบล็อกเซิร์ฟเวอร์เรา',
                facts: [
                    'ซิงก์ล่าสุด' => $last !== null ? \Illuminate\Support\Carbon::parse($last)->setTimezone('Asia/Bangkok')->format('d/m/Y H:i') : '—',
                    'ควรซิงก์ภายใน' => $allowDays.' วัน',
                ],
                url: url('/admin/import'),
                urlLabel: 'เปิดหน้านำเข้า',
                category: 'sources',
            ), 1440);
        }
    }

    /**
     * Source id => runs per week, from the same settings routes/console.php schedules from.
     *
     * @return array<string,int>
     */
    private function scheduledSources(): array
    {
        $table = json_decode((string) Setting::get('auto_import_schedules', ''), true);
        if (is_array($table) && $table !== []) {
            $out = [];
            foreach ($table as $sid => $cfg) {
                if (is_array($cfg) && ! empty($cfg['enabled'])) {
                    $days = array_filter((array) ($cfg['days'] ?? []), fn ($d) => is_numeric($d) && $d >= 0 && $d <= 6);
                    $out[(string) $sid] = $days === [] ? 7 : count(array_unique($days));
                }
            }

            return $out;
        }

        // Legacy single run over the auto_import_sources CSV.
        $days = array_filter(explode(',', (string) Setting::get('auto_import_days', '')), fn ($d) => trim($d) !== '');
        $per = $days === [] ? 7 : count(array_unique($days));
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string) Setting::get('auto_import_sources', '')))) as $sid) {
            $out[$sid] = $per;
        }

        return $out;
    }
}
