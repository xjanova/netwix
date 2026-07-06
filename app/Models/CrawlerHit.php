<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One search-engine / AI crawler fetch of a public page (see TrackPageView). Insert-only:
 * no updated_at column, so UPDATED_AT is disabled.
 */
class CrawlerHit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['bot', 'path', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
