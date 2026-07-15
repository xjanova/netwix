<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Facebook interaction (a comment, for now) on one of our clip posts, plus whether the
 * invite-DM funnel replied. Kept as an audit trail + the source of the per-user/per-title
 * cooldown. PDPA-wise we store only Facebook's own public commenter id/name — no email/phone.
 */
class FbEngagement extends Model
{
    protected $fillable = [
        'fb_user_id', 'fb_user_name', 'fb_post_id', 'comment_id',
        'content_id', 'kind', 'dm_status', 'dm_error',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
