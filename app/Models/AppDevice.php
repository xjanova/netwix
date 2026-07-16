<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One mobile-app install, upserted by the random on-device `device_key` each
 * launch (POST /api/app/telemetry). Analytics only — see the migration note.
 */
class AppDevice extends Model
{
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
}
