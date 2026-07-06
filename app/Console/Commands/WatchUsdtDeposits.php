<?php

namespace App\Console\Commands;

use App\Services\UsdtPayment;
use Illuminate\Console\Command;

/**
 * The auto-watcher: read the chain once and settle every open USDT order that
 * got paid (expires the stale ones first). Scheduled every minute in
 * routes/console.php; the "check now" button uses UsdtPayment::verify() for the
 * same result on demand, so a member never has to wait for the tick.
 */
class WatchUsdtDeposits extends Command
{
    protected $signature = 'usdt:watch';

    protected $description = 'Settle paid USDT (BSC) orders by reading the chain';

    public function handle(UsdtPayment $usdt): int
    {
        if (! $usdt->enabled()) {
            $this->info('USDT payments disabled or wallet not set — skipping.');

            return self::SUCCESS;
        }

        $r = $usdt->watch();
        $this->info("USDT watch: settled {$r['settled']} / {$r['open']} open, expired {$r['expired']}.");

        return self::SUCCESS;
    }
}
