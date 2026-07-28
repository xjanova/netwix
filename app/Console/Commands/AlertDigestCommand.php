<?php

namespace App\Console\Commands;

use App\Support\LineNotifier;
use Illuminate\Console\Command;

/**
 * Sends the hour's accumulated title-level problems to LINE as ONE message.
 *
 * Auto-suspension fires per title, so a single dead source can produce thousands of events in
 * minutes. Pushing each would bury the one alert that matters and get the channel muted, which is
 * strictly worse than not alerting at all — so [LineNotifier::noteSuspended] only accumulates, and
 * this is the single place that actually sends.
 */
class AlertDigestCommand extends Command
{
    protected $signature = 'netwix:alert-digest';

    protected $description = 'Send the accumulated "titles went un-playable" digest to the admin LINE OA.';

    public function handle(): int
    {
        if (! LineNotifier::enabled()) {
            $this->info('LINE alerts are off — nothing to send.');

            return self::SUCCESS;
        }

        $n = LineNotifier::flushDigest();
        $this->info($n > 0 ? "Reported {$n} suspended title(s) to LINE." : 'Nothing to report.');

        return self::SUCCESS;
    }
}
