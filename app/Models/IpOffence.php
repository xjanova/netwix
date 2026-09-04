<?php

namespace App\Models;

use App\Support\ScrapeGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * The ban history of one client, kept after the ban itself is gone.
 *
 * Keyed by ScrapeGuard::blockKey() — an address for IPv4, the /64 for IPv6 — so the count follows
 * the subscriber rather than whichever address a carrier has them on today.
 *
 * @see ScrapeGuard::sentenceHours() for how a count here becomes a sentence.
 */
class IpOffence extends Model
{
    protected $fillable = ['ip', 'offences', 'last_reason', 'first_at', 'last_at'];

    protected function casts(): array
    {
        return [
            'offences' => 'integer',
            'first_at' => 'datetime',
            'last_at' => 'datetime',
        ];
    }
}
