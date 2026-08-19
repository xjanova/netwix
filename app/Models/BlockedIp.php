<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An address currently refused service, with the reason and the score that earned it.
 *
 * Blocks expire on purpose (see the migration): a permanent block on a shared office address or a
 * mobile-carrier NAT eventually refuses a real viewer who was never the scraper. Only a block an
 * admin sets by hand is open-ended.
 */
class BlockedIp extends Model
{
    protected $fillable = ['ip', 'reason', 'score', 'expires_at', 'manual', 'hits'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'manual' => 'boolean',
            'score' => 'integer',
            'hits' => 'integer',
        ];
    }

    public function getActiveAttribute(): bool
    {
        return $this->manual || $this->expires_at === null || $this->expires_at->isFuture();
    }
}
