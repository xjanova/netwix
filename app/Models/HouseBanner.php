<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * One of the site's own banner creatives — see the create_house_banners migration for why these
 * exist. Picked by [self::pickFor] in whichever mode the admin chose:
 *
 *   rotate   วน     — strict round-robin, so every banner gets an equal, predictable turn
 *   random   สุ่ม    — uniform pick; equal in the long run, uneven in the short
 *   weighted เปอร์เซ็นต์ — pick in proportion to `weight`
 *
 * The eligible set is cached for a minute: these are rendered on nearly every page view, and without
 * it each one would cost a query per slot.
 */
class HouseBanner extends Model
{
    public const MODES = ['rotate', 'random', 'weighted'];

    protected $fillable = [
        'name', 'slot', 'image_path', 'image_url', 'link_url',
        'weight', 'is_active', 'starts_at', 'ends_at', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'weight' => 'integer',
            'clicks' => 'integer',
            'sort' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** Enabled AND inside its schedule window (null bounds = open-ended on that side). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** Uploaded file resolves via the public disk, else the raw URL — same convention as AdCampaign. */
    public function getImageSrcAttribute(): ?string
    {
        if ($this->image_path) {
            return Storage::url($this->image_path);
        }

        return $this->image_url ?: null;
    }

    /**
     * Choose a banner for a slot, or null when none is eligible. $mode defaults to the admin setting.
     *
     * @return array{id:int,src:string,link:?string,name:?string}|null
     */
    public static function pickFor(string $slot, ?string $mode = null): ?array
    {
        $pool = self::pool($slot);
        if ($pool === []) {
            return null;
        }

        $mode = in_array($mode, self::MODES, true) ? $mode : self::mode();

        return match ($mode) {
            'rotate' => $pool[self::cursor($slot) % count($pool)],
            'weighted' => self::weighted($pool),
            default => $pool[random_int(0, count($pool) - 1)],
        };
    }

    /** The configured rotation mode, falling back to a plain cycle. */
    public static function mode(): string
    {
        $m = (string) Setting::get('house_ads_mode', 'rotate');

        return in_array($m, self::MODES, true) ? $m : 'rotate';
    }

    /**
     * Eligible creatives for a slot, newest-priority first, as plain arrays. Cached briefly because
     * this runs on nearly every page view; [self::flush] clears it whenever an admin edits one.
     *
     * @return array<int,array{id:int,src:string,link:?string,name:?string,weight:int}>
     */
    private static function pool(string $slot): array
    {
        return Cache::remember('house_ads:pool:'.$slot, now()->addMinutes(1), function () use ($slot) {
            try {
                return static::query()->active()
                    ->whereIn('slot', [$slot, 'all'])
                    ->where(fn ($w) => $w->whereNotNull('image_path')->orWhereNotNull('image_url'))
                    ->orderByDesc('sort')->orderBy('id')
                    ->get()
                    ->map(fn (self $b) => [
                        'id' => (int) $b->id,
                        'src' => (string) $b->image_src,
                        'link' => $b->link_url ?: null,
                        'name' => $b->name,
                        'weight' => max(1, (int) $b->weight),
                    ])
                    ->all();
            } catch (\Throwable) {
                return [];   // table missing / db hiccup → no house ad, never a broken page
            }
        });
    }

    /** Monotonic per-slot counter driving round-robin. A reset (cache flush) only restarts the cycle. */
    private static function cursor(string $slot): int
    {
        $key = 'house_ads:cursor:'.$slot;
        try {
            // add() seeds the key only if absent, so a flushed cache restarts cleanly at 0.
            Cache::add($key, 0, now()->addDay());

            return (int) Cache::increment($key);
        } catch (\Throwable) {
            return random_int(0, 9999);   // no atomic counter available → behave like random mode
        }
    }

    /** @param array<int,array<string,mixed>> $pool */
    private static function weighted(array $pool): array
    {
        $total = array_sum(array_column($pool, 'weight'));
        if ($total < 1) {
            return $pool[0];
        }
        $roll = random_int(1, $total);
        foreach ($pool as $b) {
            $roll -= (int) $b['weight'];
            if ($roll <= 0) {
                return $b;
            }
        }

        return $pool[array_key_last($pool)];
    }

    /** Drop the cached pools so an admin edit shows up immediately rather than up to a minute later. */
    public static function flush(): void
    {
        foreach (array_merge(\App\Support\Ads::SLOTS, ['all']) as $slot) {
            Cache::forget('house_ads:pool:'.$slot);
        }
    }
}
