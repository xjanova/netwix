<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One USDT (BSC) payment order. See the migration for the anti-spoof design.
 * Money mutations happen in App\Services\UsdtPayment, never here.
 */
class UsdtOrder extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'purpose', 'status', 'wallet',
        'base_usdt', 'amount_usdt', 'credited_gold', 'pro_days',
        'tx_hash', 'from_address', 'confirmations', 'paid_at', 'expires_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'base_usdt' => 'decimal:6',
            'amount_usdt' => 'decimal:6',
            'credited_gold' => 'integer',
            'pro_days' => 'integer',
            'confirmations' => 'integer',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /** Pending orders that can still receive a deposit (not past their TTL). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', 'pending')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }
}
