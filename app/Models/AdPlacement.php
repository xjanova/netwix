<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A banner position offered for sale — see the create_ad_marketplace_tables migration.
 * Everything a buyer is quoted (price, size, how many share the slot, how long they may book)
 * lives here so the admin can reprice without a deploy.
 */
class AdPlacement extends Model
{
    protected $fillable = [
        'slot', 'name', 'blurb', 'width', 'height', 'price_usdt_per_day',
        'max_concurrent', 'max_days', 'max_upload_kb', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'price_usdt_per_day' => 'decimal:2',
            'max_concurrent' => 'integer',
            'max_days' => 'integer',
            'max_upload_kb' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(AdBooking::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Aspect ratio as a CSS-friendly string, so the crop box always matches what will be shown. */
    public function getRatioAttribute(): string
    {
        return max(1, (int) $this->width).' / '.max(1, (int) $this->height);
    }

    /** Upload ceiling in bytes, for the validator and the UI hint. */
    public function getMaxUploadBytesAttribute(): int
    {
        return max(1, (int) $this->max_upload_kb) * 1024;
    }
}
