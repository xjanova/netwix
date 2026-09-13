<?php

namespace App\Console\Commands;

use App\Support\AdminAlerts;
use Illuminate\Console\Command;

/**
 * Sends the hour's accumulated events to the admin's alert channels as ONE message each: titles
 * that went un-playable, and new members.
 *
 * Auto-suspension fires per title, so a single dead source can produce thousands of events in
 * minutes. Pushing each would bury the one alert that matters and get the channel muted, which is
 * strictly worse than not alerting at all — so [AdminAlerts::noteSuspended] (and noteSignup) only
 * accumulate, and this is the single place that actually sends.
 */
class AlertDigestCommand extends Command
{
    protected $signature = 'netwix:alert-digest';

    protected $description = 'Send the hourly digests (un-playable titles, new members) to the admin alert channels (Telegram / LINE).';

    public function handle(): int
    {
        if (! AdminAlerts::enabled()) {
            $this->info('Admin alerts are off — nothing to send.');

            return self::SUCCESS;
        }

        $n = AdminAlerts::flushDigest();
        $s = AdminAlerts::flushSignups();
        $this->info("Reported {$n} suspended title(s), {$s} new member(s).");

        return self::SUCCESS;
    }
}
