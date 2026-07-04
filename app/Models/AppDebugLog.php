<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A diagnostic line shipped by the mobile app. Append-only, pruned after 14
 * days. Only `created_at` is tracked (no updates).
 */
class AppDebugLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'level', 'event', 'message', 'context',
        'user_id', 'app_version', 'platform', 'ip', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
