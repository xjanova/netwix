<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An address currently refused service, with the reason and the score that earned it.
 *
 * Blocks expire on purpose (see the migration): a permanent block on a shared office address or a
 * mobile-carrier NAT eventually refuses a real viewer who was never the scraper. A block is
 * open-ended only when an admin set it by hand, or when the same client has now been banned enough
 * times that "they were rotated onto a shared address" has stopped being the likely explanation.
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

    /**
     * Is this block still in force?
     *
     * Permanence is `expires_at === null`, and NOTHING else. `manual` records who placed the block,
     * not how long it lasts — those are different facts and reading one as the other made the admin's
     * own choice a no-op: the manual-block form offers 1 ชม. through 30 วัน alongside ถาวร, wrote the
     * expiry it was told to, and then this method short-circuited on `manual` and kept the block
     * alive forever. An admin who picked six hours got a permanent ban and was never told.
     *
     * With permanence carried by the expiry alone, both paths that mean "forever" already say so:
     * the block form writes a NULL expiry for ถาวร, and so does setDuration(0).
     */
    public function getActiveAttribute(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * The SQL half of `active` — and the reason it exists as a scope rather than being retyped.
     *
     * The predicate was written out by hand in two places as `manual = 1 OR expires_at > now()`,
     * which reads as "manual or unexpired" but silently means something narrower: a row with a NULL
     * expiry matches NEITHER arm, because `NULL > now()` is NULL, not true. That was harmless while
     * `expires_at IS NULL` only ever happened together with `manual = 1`. It stopped being harmless
     * the moment an AUTOMATIC block could be permanent: such a row is `manual = 0, expires_at = NULL`
     * and would have been dropped from the firewall sync and undercounted in the admin totals —
     * shown as banned in the list, and quietly served by Apache. Exactly the shape of the earlier
     * FILTER_VALIDATE_IP bug, where a block was present on screen and absent from .htaccess.
     *
     * One definition, used everywhere, so the next kind of block cannot reintroduce it.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
    }
}
