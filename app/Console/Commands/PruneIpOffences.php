<?php

namespace App\Console\Commands;

use App\Models\IpOffence;
use App\Support\ScrapeGuard;
use Illuminate\Console\Command;

/**
 * Drop ban histories that have aged out of the escalation window.
 *
 * These rows are already inert — ScrapeGuard restarts the ladder at the first rung for any client
 * whose last strike is older than the window, so keeping them changes no decision. What they do is
 * accumulate: one row per address ever banned, kept for the life of the site. Deleting them is the
 * same forgiveness the code already applies, made real in the table.
 */
class PruneIpOffences extends Command
{
    protected $signature = 'netwix:security:prune-offences';

    protected $description = 'ลบประวัติการโดนแบนที่เก่าเกินกำหนด (ไม่มีผลกับการนับโทษอยู่แล้ว)';

    public function handle(): int
    {
        $cutoff = now()->subDays(ScrapeGuard::offenceMemoryDays());
        $deleted = IpOffence::where('last_at', '<', $cutoff)->delete();

        $this->info("ลบประวัติเก่า {$deleted} รายการ (ก่อน {$cutoff->toDateString()})");

        return self::SUCCESS;
    }
}
