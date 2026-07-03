<?php

namespace App\Support;

/**
 * Content maturity ratings. "18+" and "20+" are ADULT ratings: hidden from kids profiles and
 * playable only by Pro members (18+ = censored, 20+ = uncensored — e.g. anime). Everything else
 * is open. Kept as a tiny value class so the model, admin form, and gating all agree on the set.
 */
class Maturity
{
    /** Selectable ratings, low → high, for the admin dropdown. */
    public const OPTIONS = ['ทุกวัย', '7+', '13+', '16+', '18+', '20+'];

    /** Ratings that are adult-only (kids can't see) AND require Pro to watch. */
    public const ADULT = ['18+', '20+'];

    public static function isAdult(?string $maturity): bool
    {
        return in_array((string) $maturity, self::ADULT, true);
    }
}
