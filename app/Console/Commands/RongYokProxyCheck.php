<?php

namespace App\Console\Commands;

use App\Support\RongYokProxyPool;
use Illuminate\Console\Command;

class RongYokProxyCheck extends Command
{
    protected $signature = 'netwix:rongyok-proxies';

    protected $description = 'Refresh verified free proxies for RongYok without a home relay';

    public function handle(): int
    {
        if (! RongYokProxyPool::enabled()) {
            $this->info('Free proxy selection is disabled.');

            return self::SUCCESS;
        }
        $this->info('Verified proxies: '.RongYokProxyPool::refresh());

        return self::SUCCESS;
    }
}
