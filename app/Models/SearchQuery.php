<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One on-site search term (see SearchController::index). Insert-only: no updated_at column.
 * Deliberately anonymous — no user linkage — so it stays PDPA-safe while still driving the
 * admin "content gap" report.
 */
class SearchQuery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['term', 'results', 'is_member', 'created_at'];

    protected $casts = [
        'results' => 'integer',
        'is_member' => 'boolean',
        'created_at' => 'datetime',
    ];
}
