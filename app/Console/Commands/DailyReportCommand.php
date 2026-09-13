<?php

namespace App\Console\Commands;

use App\Models\AdBooking;
use App\Models\Content;
use App\Models\ImportLog;
use App\Models\PageView;
use App\Models\UsdtOrder;
use App\Models\User;
use App\Models\WatchProgress;
use App\Support\AdminAlerts;
use App\Support\Alerts\Alert;
use App\Support\SourceHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Yesterday on one card, every morning: money in, new members, who watched, the most-watched
 * titles, source health, and anything waiting on the owner (ads to approve, failed jobs).
 *
 * Only MEASURED numbers — the dashboard once showed invented revenue and ratings, and a report the
 * owner cannot trust is worse than none. Revenue is paid `usdt_orders` (all real money flows there),
 * watching is `watch_progress.last_watched_at`, traffic is the bot-free `page_views`.
 *
 *   php artisan netwix:daily-report              # yesterday (Thai time)
 *   php artisan netwix:daily-report --date=2026-09-12
 */
class DailyReportCommand extends Command
{
    private const TZ = 'Asia/Bangkok';

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    protected $signature = 'netwix:daily-report {--date= : the Thai calendar day to report (default: yesterday)}';

    protected $description = 'Send yesterday\'s summary (money, members, watching, top titles, health) to the admin alert channels.';

    public function handle(): int
    {
        if (! AdminAlerts::wants('daily')) {
            $this->info('No channel takes the daily report — skipping.');

            return self::SUCCESS;
        }

        $day = $this->option('date')
            ? CarbonImmutable::parse((string) $this->option('date'), self::TZ)->startOfDay()
            : CarbonImmutable::now(self::TZ)->subDay()->startOfDay();
        // Stored timestamps are UTC; the day being reported is a Thai calendar day.
        $from = $day->utc();
        $to = $day->addDay()->utc();
        $prevFrom = $day->subDay()->utc();

        $paid = UsdtOrder::whereNotNull('paid_at')->where('paid_at', '>=', $from)->where('paid_at', '<', $to);
        $revenue = (float) (clone $paid)->sum('amount_usdt');
        $orders = (clone $paid)->count();
        $byPurpose = (clone $paid)->select('purpose', DB::raw('count(*) as n'))->groupBy('purpose')->pluck('n', 'purpose')->all();

        $members = User::where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $watchers = WatchProgress::where('last_watched_at', '>=', $from)->where('last_watched_at', '<', $to)->distinct()->count('profile_id');
        $watchersPrev = WatchProgress::where('last_watched_at', '>=', $prevFrom)->where('last_watched_at', '<', $from)->distinct()->count('profile_id');
        $views = PageView::where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $viewsPrev = PageView::where('created_at', '>=', $prevFrom)->where('created_at', '<', $from)->count();

        // Most-watched titles: distinct profiles that watched each one that day.
        $top = WatchProgress::where('last_watched_at', '>=', $from)->where('last_watched_at', '<', $to)
            ->select('content_id', DB::raw('count(distinct profile_id) as n'))
            ->groupBy('content_id')->orderByDesc('n')->limit(5)->pluck('n', 'content_id')->all();
        $titles = Content::withoutGlobalScopes()->whereIn('id', array_keys($top))->pluck('title', 'id')->all();
        $bars = [];
        foreach ($top as $id => $n) {
            $bars[mb_substr((string) ($titles[$id] ?? '#'.$id), 0, 40)] = (int) $n;
        }

        $lines = [];
        if ($orders > 0) {
            $parts = [];
            foreach (['gold' => 'เหรียญทอง', 'pro' => 'Pro', 'ad' => 'โฆษณา'] as $p => $label) {
                if (! empty($byPurpose[$p])) {
                    $parts[] = $label.' '.$byPurpose[$p];
                }
            }
            $lines[] = "• ออเดอร์ที่จ่ายแล้ว {$orders} รายการ (".implode(' · ', $parts).')';
        } else {
            $lines[] = '• ยังไม่มีออเดอร์ที่จ่ายเงินในวันนั้น';
        }
        $imported = (int) ImportLog::where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('imported');
        $lines[] = '• นำเข้าเรื่องใหม่ '.number_format($imported).' เรื่อง';

        // Things waiting on a human go last, so the report ends with what to do.
        $toApprove = AdBooking::where('status', 'paid')->count();
        if ($toApprove > 0) {
            $lines[] = "• ⏳ โฆษณารออนุมัติ {$toApprove} รายการ";
        }
        try {
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', $from)->where('failed_at', '<', $to)->count();
            if ($failed > 0) {
                $lines[] = "• งานเบื้องหลังล้มเหลว {$failed} งาน";
            }
        } catch (Throwable) {
        }
        if (($free = @disk_free_space(storage_path())) !== false) {
            $lines[] = '• ดิสก์เหลือว่าง '.number_format($free / 1024 ** 3, 1).' GB';
        }
        $down = array_keys(SourceHealth::down());
        if ($down !== []) {
            $lines[] = '• แหล่งที่ยังดึงลิ้งค์ไม่ได้: '.implode(', ', $down);
        }

        $chips = array_map(fn ($v) => empty($v['down']), SourceHealth::all());

        $sent = AdminAlerts::send(new Alert(
            key: 'daily-report:'.$day->toDateString(),
            level: $down !== [] || $toApprove > 0 ? Alert::WARNING : Alert::OK,
            title: 'สรุปประจำวัน '.$day->day.' '.self::MONTHS[$day->month - 1].' '.($day->year + 543),
            body: implode("\n", $lines),
            facts: [
                'รายได้' => ($revenue > 0 ? rtrim(rtrim(number_format($revenue, 2), '0'), '.') : '0').' USDT',
                'สมาชิกใหม่' => number_format($members).' คน',
                'คนดูหนัง' => number_format($watchers).' คน'.self::trend($watchers, $watchersPrev),
                'เปิดหน้าเว็บ' => number_format($views).self::trend($views, $viewsPrev),
            ],
            bars: $bars,
            chips: $chips,
            url: url('/admin'),
            urlLabel: 'เปิดแดชบอร์ด',
            category: 'daily',
            barsLabel: 'เรื่องที่มีคนดูมากที่สุด (คน)',
        ), 1200);

        $this->info($sent ? 'Daily report sent for '.$day->toDateString().'.' : 'Not sent (already sent, or no channel delivered it).');

        return self::SUCCESS;
    }

    /** " (+12%)" against the day before — omitted when there is nothing to compare with. */
    private static function trend(int $now, int $before): string
    {
        if ($before <= 0) {
            return '';
        }
        $pct = (int) round(($now - $before) / $before * 100);

        return $pct === 0 ? ' (เท่าเดิม)' : ' ('.($pct > 0 ? '+' : '').$pct.'%)';
    }
}
