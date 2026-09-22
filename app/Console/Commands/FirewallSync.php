<?php

namespace App\Console\Commands;

use App\Support\FirewallBlocklist;
use Illuminate\Console\Command;

/**
 * Re-write the Apache blocklist in public/.htaccess from the database.
 *
 * The block is only ever written when an admin changes a ban or ScrapeGuard issues a new one. Nothing
 * wrote it back after a deploy — and deploy.sh does `git checkout -f`, which resets the tracked
 * public/.htaccess and wipes the managed block with it. Every ban then fell back to PHP (a worker per
 * refusal, on a pool shared with every site on the box) until the next ban happened to re-sync it.
 * deploy.sh runs this right after `artisan up`: FirewallBlocklist::sync() probes the live site and
 * rolls back on failure, so it must not run while the site still answers 503.
 *
 * A no-op when the firewall feature is switched off.
 */
class FirewallSync extends Command
{
    protected $signature = 'netwix:security:firewall-sync';

    protected $description = 'เขียนรายชื่อ IP ที่ถูกบล็อกลง .htaccess ใหม่จากฐานข้อมูล (ใช้หลัง deploy)';

    public function handle(): int
    {
        if (! FirewallBlocklist::enabled()) {
            $this->info('ไฟร์วอลล์ .htaccess ปิดอยู่ — ไม่ต้องเขียน');

            return self::SUCCESS;
        }

        $result = FirewallBlocklist::sync();
        if (! $result['ok']) {
            $this->error('เขียนไม่สำเร็จ: '.$result['error']);

            return self::FAILURE;
        }

        $this->info("เขียนรายชื่อบล็อกแล้ว {$result['count']} รายการ");

        return self::SUCCESS;
    }
}
