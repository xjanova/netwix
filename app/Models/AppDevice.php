<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One mobile-app install, upserted by the random on-device `device_key` each
 * launch (POST /api/app/telemetry). Analytics only — see the migration note.
 */
class AppDevice extends Model
{
    /**
     * How long an install may stay silent before we call it gone.
     *
     * A real uninstall can never be observed: Android gives an app no callback when it is
     * being removed, the APK is sideloaded so there is no Play Console report, and pushes
     * go to FCM *topics* so there is no per-device token to come back UNREGISTERED. Silence
     * is the only signal available.
     */
    public const GONE_AFTER_DAYS = 30;

    protected $fillable = [
        'device_key', 'platform', 'os_version', 'device_model', 'app_version',
        'locale', 'screen', 'user_id', 'launches', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'launches' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Installs presumed removed — nothing heard from them in GONE_AFTER_DAYS.
     *
     * Deliberately DERIVED from `last_seen_at` rather than stored in a flag column set by a
     * nightly job. The moment the app opens again, TelemetryController writes `last_seen_at`
     * and the device drops out of this scope on its own: "comes back → clears itself" is not
     * code that has to run, it is the absence of code that could fail to run. No cron, no
     * column, nothing to drift out of sync.
     */
    public function scopeGone(Builder $q): Builder
    {
        return $q->where('last_seen_at', '<', now()->subDays(self::GONE_AFTER_DAYS));
    }

    /** The complement: heard from within the window, so presumed still installed. */
    public function scopeStillHere(Builder $q): Builder
    {
        return $q->where('last_seen_at', '>=', now()->subDays(self::GONE_AFTER_DAYS));
    }

    /** Is this one presumed gone right now? Same rule as [scopeGone], for a single row. */
    public function isPresumedGone(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->lt(now()->subDays(self::GONE_AFTER_DAYS));
    }
}
