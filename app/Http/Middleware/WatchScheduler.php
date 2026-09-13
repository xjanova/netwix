<?php

namespace App\Http\Middleware;

use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Dead man's switch for the cron.
 *
 * Everything scheduled — imports, the source canary, the payment watcher, every other alert — runs
 * from ONE cron line, and when that line is missing nothing runs and nothing complains: it happened
 * for 9 days once (deploy/ensure-cron.sh), and the scheduler was off again until 2026-07-13. A dead
 * scheduler cannot report its own death, so the check lives on the web side: `netwix:watchdog`
 * stamps a heartbeat every 5 minutes, and page views notice when it stops.
 *
 * Runs in terminate(), after the response is already sent — a visitor never waits for it.
 */
class WatchScheduler
{
    private const STALE_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // One page view in ten is plenty to notice a cron that has been dead for half an hour, and
        // it keeps the cache read off the other nine.
        if (random_int(1, 10) !== 1 && ! app()->runningUnitTests()) {
            return;
        }
        try {
            $beat = Cache::get('scheduler:heartbeat');
            if ($beat === null) {
                // Never seen (fresh deploy, cleared cache): start the clock now. If the cron really
                // is dead, the next check half an hour from now finds it stale.
                Cache::add('scheduler:heartbeat', now()->timestamp, now()->addDays(30));

                return;
            }
            $minutes = intdiv(now()->timestamp - (int) $beat, 60);
            if ($minutes < self::STALE_MINUTES) {
                return;
            }

            AdminAlerts::send(new Alert(
                key: 'scheduler-dead',
                level: Alert::CRITICAL,
                title: 'ตัวตั้งเวลา (cron) ของเซิร์ฟเวอร์หยุดทำงาน',
                body: "งานอัตโนมัติทุกอย่างหยุดหมด: นำเข้าเรื่องใหม่ · ตรวจแหล่งหนัง · ตรวจยอดโอน USDT · สรุปรายชั่วโมง/รายวัน\n"
                    .'ตรวจ crontab บนเซิร์ฟเวอร์ว่ายังมีบรรทัด php artisan schedule:run (รัน bash deploy/ensure-cron.sh)',
                facts: ['เงียบไปแล้ว' => $minutes >= 120 ? intdiv($minutes, 60).' ชม.' : $minutes.' นาที', 'ปกติเต้นทุก' => '5 นาที'],
                url: url('/admin'),
                urlLabel: 'เปิดหน้าแอดมิน',
                category: 'system',
            ), 180, wait: true);    // the response is already sent, and deferred callbacks have run
        } catch (Throwable) {
            // A health check must never be the thing that breaks a page.
        }
    }
}
