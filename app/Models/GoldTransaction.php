<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ledger row for a gold-coin change (mirror of CoinTransaction for silver). */
class GoldTransaction extends Model
{
    protected $fillable = ['user_id', 'kind', 'amount', 'meta'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'meta' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
