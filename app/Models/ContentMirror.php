<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alternate place the same title can be streamed from — see the create_content_mirrors migration.
 * Rows are created by [App\Support\MirrorLinker] (duplicates found at import time, or by the
 * netwix:link-mirrors backfill) and by the admin force-link flow; they're walked and health-tracked by
 * [App\Support\MirrorRotation].
 */
class ContentMirror extends Model
{
    protected $fillable = ['content_id', 'source', 'source_key', 'priority', 'is_manual'];

    protected function casts(): array
    {
        return [
            'is_manual' => 'boolean',
            'priority' => 'integer',
            'fail_streak' => 'integer',
            'last_ok_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /**
     * Chain order: admin pins first, then the links that have been failing least. `priority` breaks
     * ties, and the id keeps it stable so the same viewer doesn't get a different order each poll.
     */
    public function scopeInChainOrder($query)
    {
        return $query->orderByDesc('is_manual')->orderBy('fail_streak')->orderBy('priority')->orderBy('id');
    }
}
