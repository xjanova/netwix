<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One human page view (see TrackPageView middleware). Insert-only: there is no
 * updated_at column, so UPDATED_AT is disabled.
 */
class PageView extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['path', 'is_member', 'created_at'];

    protected $casts = [
        'is_member' => 'boolean',
        'created_at' => 'datetime',
    ];
}
