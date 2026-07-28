<?php

namespace App\Support;

use App\Models\AdBooking;
use App\Models\AdPlacement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The numbers the /advertise page is built on: how full a placement's queue is, how many people will
 * actually see the ad, and what a run costs.
 *
 * The reach figures come from OUR OWN traffic — `page_views` for the web and `app_devices` for the
 * app — not from an invented rate card. An advertiser is quoted a share of measured demand, so the
 * quote falls when traffic falls. That is the honest way round, and it also means the number cannot
 * be inflated by accident: there is no constant to tune.
 */
class AdMarketplace
{
    /** How far ahead the calendar and the queue gauge look. */
    public const HORIZON_DAYS = 60;

    /**
     * Occupancy per day for a placement over a date range: how many of `max_concurrent` slots are
     * taken. Used for the queue gauge, the calendar, and to refuse an overbooked date range.
     *
     * @return array<string,int> "Y-m-d" => bookings holding that day
     */
    public function occupancy(AdPlacement $placement, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = 0;
        }

        $bookings = AdBooking::query()
            ->where('ad_placement_id', $placement->id)
            ->holdingCapacity()
            ->whereDate('ends_at', '>=', $from->toDateString())
            ->whereDate('starts_at', '<=', $to->toDateString())
            ->get(['starts_at', 'ends_at']);

        foreach ($bookings as $b) {
            $s = CarbonImmutable::parse($b->starts_at)->max($from);
            $e = CarbonImmutable::parse($b->ends_at)->min($to);
            for ($d = $s; $d->lte($e); $d = $d->addDay()) {
                $key = $d->toDateString();
                if (isset($days[$key])) {
                    $days[$key]++;
                }
            }
        }

        return $days;
    }

    /** True when every day in the range still has room. */
    public function hasRoom(AdPlacement $placement, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        foreach ($this->occupancy($placement, $from, $to) as $taken) {
            if ($taken >= max(1, (int) $placement->max_concurrent)) {
                return false;
            }
        }

        return true;
    }

    /** 0–100: how full the NEXT 30 days are on average ("คิวแน่นแค่ไหน"). */
    public function queuePressure(AdPlacement $placement): int
    {
        $from = CarbonImmutable::today();
        $days = $this->occupancy($placement, $from, $from->addDays(29));
        if ($days === []) {
            return 0;
        }
        $cap = max(1, (int) $placement->max_concurrent);

        return (int) round(min(1, array_sum($days) / (count($days) * $cap)) * 100);
    }

    /**
     * Estimated impressions this placement delivers to ONE advertiser, per day and per month.
     *
     * Measured, then discounted honestly:
     *  - web: average daily page views over the last 30 days, restricted to the pages the slot is
     *    actually on (the in-feed unit only exists on title pages, so it must not be quoted whole-site
     *    traffic);
     *  - app: active devices seen in the last 30 days, as a floor for app impressions;
     *  - divided by `max_concurrent`, because that is how many advertisers share the rotation.
     *
     * @return array{per_day:int,per_month:int,basis_days:int}
     */
    public function reach(AdPlacement $placement): array
    {
        $daily = Cache::remember('admarket:reach:'.$placement->slot, now()->addHours(6),
            fn () => $this->measuredDailyViews($placement->slot));

        $share = max(1, (int) $placement->max_concurrent);
        $perDay = (int) floor($daily / $share);

        return [
            'per_day' => $perDay,
            'per_month' => $perDay * 30,
            'basis_days' => 30,
        ];
    }

    /**
     * Average daily impressions available in a slot, from real traffic. Returns 0 rather than a guess
     * when there is no data — quoting a number we cannot back would be the one unforgivable thing on
     * a page where people are about to spend money.
     */
    private function measuredDailyViews(string $slot): int
    {
        $since = now()->subDays(30);

        try {
            $q = DB::table('page_views')->where('created_at', '>=', $since);

            // The in-feed unit only renders on a title page; everything else is site-wide.
            if ($slot === 'infeed') {
                $q->where('path', 'like', 'title/%');
            }

            $web = (int) $q->count();
        } catch (\Throwable) {
            $web = 0;
        }

        try {
            // One app launch is at least one impression opportunity for the app's banner slot.
            $app = (int) DB::table('app_devices')->where('updated_at', '>=', $since)->count();
        } catch (\Throwable) {
            $app = 0;
        }

        return (int) floor(($web + $app) / 30);
    }

    /** Total price for a run, rounded to cents. */
    public function price(AdPlacement $placement, int $days): float
    {
        return round((float) $placement->price_usdt_per_day * max(1, $days), 2);
    }

    /**
     * Calendar rows for a placement. The PUBLIC view gets only "how many slots are taken" per day —
     * never who booked them, which is the owner's rule: "ไม่บอกว่าใคร มีแต่แอดมินที่เห็น".
     *
     * @return array<int,array{date:string,taken:int,cap:int,full:bool}>
     */
    public function calendar(AdPlacement $placement, ?CarbonImmutable $from = null, int $days = self::HORIZON_DAYS): array
    {
        $from ??= CarbonImmutable::today();
        $cap = max(1, (int) $placement->max_concurrent);
        $out = [];

        foreach ($this->occupancy($placement, $from, $from->addDays($days - 1)) as $date => $taken) {
            $out[] = ['date' => $date, 'taken' => $taken, 'cap' => $cap, 'full' => $taken >= $cap];
        }

        return $out;
    }
}
