<?php

namespace App\Console\Commands;

use App\Models\AdBooking;
use App\Support\Ads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Folds the cached per-booking impression counters into the rows.
 *
 * [App\Support\Ads::paidSlot] increments a cache counter on every render rather than the database:
 * a banner that costs a write per page view is a banner that costs more than it earns. This runs on a
 * schedule and moves the accumulated counts across, so the advertiser's dashboard and the admin
 * calendar show real delivery without that per-request cost.
 *
 * Also retires bookings whose window has passed, which is what keeps [AdBooking::scopeRunnable] cheap
 * and stops a finished campaign lingering in the rotation.
 */
class AdImpressionsCommand extends Command
{
    protected $signature = 'netwix:ad-impressions';

    protected $description = 'Flush cached ad impression counts into ad_bookings and retire finished campaigns.';

    public function handle(): int
    {
        $moved = 0;

        // Only bookings that could have been shown recently can have counters worth reading.
        $ids = AdBooking::whereIn('status', ['approved', 'finished'])
            ->whereDate('ends_at', '>=', now()->subDays(2)->toDateString())
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $n = (int) Cache::pull('admarket:imp:'.$id, 0);
            } catch (Throwable $e) {
                continue;
            }
            if ($n > 0) {
                AdBooking::whereKey($id)->update(['impressions' => DB::raw("impressions + {$n}")]);
                $moved += $n;
            }
        }

        $done = AdBooking::where('status', 'approved')
            ->whereDate('ends_at', '<', now()->toDateString())
            ->update(['status' => 'finished']);

        // The live-rotation cache is keyed per slot and only lives a minute, but clearing it here
        // means a just-retired campaign stops being served immediately rather than up to 60s later.
        foreach (Ads::SLOTS as $slot) {
            Cache::forget('admarket:live:'.$slot);
        }

        $this->info("Impressions flushed: {$moved} · campaigns finished: {$done}");

        return self::SUCCESS;
    }
}
