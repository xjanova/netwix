<?php

namespace App\Models;

use App\Support\ScrapeGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * One observation of scraping-shaped behaviour. Insert-only, so there is no updated_at.
 *
 * @see \App\Support\ScrapeGuard for what writes these and why.
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ip', 'reason', 'score', 'method', 'path', 'user_agent', 'meta', 'created_at'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'score' => 'integer',
        ];
    }

    /**
     * Thai label for the admin table — the reason codes are written for machines, not people.
     *
     * Read from the ScrapeGuard rule catalogue rather than kept here: this list and that one were two
     * places to add a rule, which is one place too many. A new detection now shows up worded in the
     * admin table and on the owner's phone without anyone touching this file.
     */
    public function getReasonLabelAttribute(): string
    {
        return ScrapeGuard::label((string) $this->reason);
    }
}
