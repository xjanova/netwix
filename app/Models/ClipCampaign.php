<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A clip marketing campaign: a standing rule that auto-cuts + auto-posts a clip on a
 * schedule. See the create-table migration for the field-by-field intent.
 *
 * Scheduling contract (mirrors the Fortune Bot campaign system):
 *   - `slots` are "HH:MM" post times; `days` restricts to weekdays (empty = every day).
 *   - The publisher runs every 5 minutes and asks {@see dueSlot()} whether "now" falls in
 *     a slot's 5-minute window. The DB unique key on clip_campaign_posts makes a repeat
 *     fire inside that window a no-op, so exactly one post is produced per slot per day.
 */
class ClipCampaign extends Model
{
    /** How wide a slot's catch window is. Must be >= the publisher's cron cadence. */
    public const SLOT_WINDOW_MINUTES = 5;

    /**
     * Slots are wall-clock TIMES the admin types (e.g. "18:00" = 6 โมงเย็น). The app runs in
     * UTC (config/app.php), so every slot comparison happens in this timezone instead — a slot
     * means Thai local time, not UTC. If this weren't here, "18:00" would post at 01:00 Thai.
     */
    public const TZ = 'Asia/Bangkok';

    protected $fillable = [
        'name', 'slug', 'is_enabled',
        'content_type', 'exclude_type', 'genre_id', 'source', 'content_id', 'pick', 'include_adult', 'avoid_recent_days',
        'duration', 'start_mode', 'duration_max', 'full_episode', 'episode_pick', 'aspect',
        'targets',
        'days', 'slots',
        'last_run_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'include_adult' => 'boolean',
            'full_episode' => 'boolean',
            'duration' => 'integer',
            'duration_max' => 'integer',
            'avoid_recent_days' => 'integer',
            'slots' => 'array',
            'last_run_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /** A campaign can be pinned to one fixed title instead of auto-picking. */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ClipCampaignPost::class, 'campaign_id');
    }

    // ---- schedule helpers ---------------------------------------------------

    /**
     * Canonicalise a slot string to "HH:MM" (zero-padded, 24h). The ONE place this
     * happens — slot_time is a unique key, so every writer/reader must agree byte-for-byte.
     */
    public static function normalizeSlot(string $slot): ?string
    {
        $slot = trim($slot);
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $slot, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $h, $min);
    }

    /** @return array<int, string> normalized, de-duplicated, sorted slot times */
    public function slotList(): array
    {
        $slots = array_filter(array_map(
            fn ($s) => self::normalizeSlot((string) $s),
            is_array($this->slots) ? $this->slots : []
        ));
        $slots = array_values(array_unique($slots));
        sort($slots);

        return $slots;
    }

    /** @return array<int, int> weekday numbers 0-6 this campaign runs on; [] = every day */
    public function dayList(): array
    {
        return array_values(array_filter(
            array_map('intval', array_filter(explode(',', (string) $this->days), fn ($d) => trim($d) !== '')),
            fn ($d) => $d >= 0 && $d <= 6
        ));
    }

    /** @return array<int, string> Facebook surfaces to post to (subset of reels|feed) */
    public function targetList(): array
    {
        $targets = array_map('trim', explode(',', (string) $this->targets));

        return array_values(array_intersect(['reels', 'feed'], $targets)) ?: ['feed'];
    }

    public function runsOnDay(CarbonInterface $when): bool
    {
        $days = $this->dayList();

        return $days === [] || in_array($when->dayOfWeek, $days, true);
    }

    /**
     * If "now" falls inside a slot's catch window today (and today is an allowed weekday),
     * return that slot ("HH:MM"); otherwise null. Only the first matching slot is returned —
     * two slots inside the same 5-min window would be a misconfiguration.
     */
    public function dueSlot(CarbonInterface $now): ?string
    {
        if (! $this->runsOnDay($now)) {
            return null;
        }
        foreach ($this->slotList() as $slot) {
            [$h, $m] = array_map('intval', explode(':', $slot));
            $slotAt = $now->copy()->setTime($h, $m, 0);
            // now within [slotAt, slotAt + window) — never fires before the slot, never
            // straddles midnight (a 23:5x slot simply isn't caught, same as Fortune Bot).
            if ($now->greaterThanOrEqualTo($slotAt) && $now->lessThan($slotAt->copy()->addMinutes(self::SLOT_WINDOW_MINUTES))) {
                return $slot;
            }
        }

        return null;
    }

    /** IDs of titles this campaign has posted within `avoid_recent_days` (repeat guard). */
    public function recentlyPostedContentIds(): array
    {
        $days = max(0, (int) $this->avoid_recent_days);
        if ($days === 0) {
            return [];
        }

        return $this->posts()
            ->where('post_date', '>=', Carbon::today(self::TZ)->subDays($days)->toDateString())
            ->whereNotNull('content_id')
            ->pluck('content_id')->unique()->values()->all();
    }

    /**
     * When this campaign will next post — the earliest future slot on an allowed weekday,
     * in Thai time. Null if it has no slots. Used for the "โพสต์ถัดไป" hint on the index.
     */
    public function nextRunAt(?CarbonInterface $from = null): ?Carbon
    {
        $slots = $this->slotList();
        if (! $slots) {
            return null;
        }
        $from = $from ? $from->copy()->setTimezone(self::TZ) : Carbon::now(self::TZ);
        $days = $this->dayList();

        for ($d = 0; $d <= 14; $d++) {
            $day = $from->copy()->addDays($d);
            if ($days !== [] && ! in_array($day->dayOfWeek, $days, true)) {
                continue;
            }
            foreach ($slots as $slot) {
                [$h, $m] = array_map('intval', explode(':', $slot));
                $at = $day->copy()->setTime($h, $m, 0);
                if ($at->greaterThan($from)) {
                    return $at;
                }
            }
        }

        return null;
    }
}
