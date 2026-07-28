<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One purchased banner run — see the create_ad_marketplace_tables migration for the status flow.
 *
 * Money and approval are deliberately separate: a booking is paid BEFORE an admin reviews it, and a
 * rejection is not refunded (the buyer accepts that in writing at checkout — `terms_accepted_at`).
 * So `status` alone never decides whether an ad renders; [self::scopeRunnable] does, and it requires
 * approval AND payment AND the date window together.
 */
class AdBooking extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'ad_placement_id', 'title', 'image_path',
        'link_url', 'link_final_url', 'starts_at', 'ends_at', 'days', 'price_usdt',
        'status', 'review_note', 'reviewed_at', 'reviewed_by', 'usdt_order_id',
        'terms_accepted_at', 'screen_result',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'days' => 'integer',
            'price_usdt' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'screen_result' => 'array',
            'impressions' => 'integer',
            'clicks' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(AdPlacement::class, 'ad_placement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(UsdtOrder::class, 'usdt_order_id');
    }

    /**
     * Short, non-sequential public code. Re-rolled on the (vanishingly rare) collision rather than
     * relying on the unique index to throw — a buyer hitting a 500 at checkout would be the worst
     * possible place to discover a duplicate.
     */
    public static function newReference(): string
    {
        do {
            $ref = 'AD-'.strtoupper(Str::random(8));
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }

    /**
     * Bookings that may actually be shown right now: approved by a human, inside their window.
     * Anything else — paid but unreviewed, rejected, expired — renders nothing.
     */
    public function scopeRunnable(Builder $q): Builder
    {
        return $q->where('status', 'approved')
            ->whereNotNull('image_path')
            ->whereDate('starts_at', '<=', now())
            ->whereDate('ends_at', '>=', now());
    }

    /** Bookings that OCCUPY capacity on a date range — approved or paid-and-awaiting-review. */
    public function scopeHoldingCapacity(Builder $q): Builder
    {
        return $q->whereIn('status', ['approved', 'paid', 'awaiting_payment']);
    }

    public function getImageSrcAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Customer-facing Thai status, kept in one place so the two dashboards can't disagree. */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'ฉบับร่าง',
            'awaiting_payment' => 'รอชำระเงิน',
            'paid' => 'ชำระแล้ว — รอตรวจอนุมัติ',
            'approved' => $this->starts_at?->isFuture() ? 'อนุมัติแล้ว — รอถึงวันเริ่ม' : 'กำลังแสดงอยู่',
            'rejected' => 'ไม่ผ่านการตรวจ — แก้ไขแล้วส่งใหม่ได้',
            'expired' => 'หมดอายุ (ไม่ได้ชำระเงิน)',
            'finished' => 'จบแคมเปญแล้ว',
            default => $this->status,
        };
    }
}
