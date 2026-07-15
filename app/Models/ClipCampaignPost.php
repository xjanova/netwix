<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scheduled run of a clip campaign — the row that makes a (campaign, date, slot)
 * post idempotent. Created by the publisher the instant a slot comes due; its unique key
 * blocks any second post for the same slot. Walks pending → cutting → posted (or failed).
 */
class ClipCampaignPost extends Model
{
    protected $fillable = [
        'campaign_id', 'post_date', 'slot_time', 'status', 'attempts',
        'content_id', 'tried_content_ids', 'marketing_clip_id', 'dry_run', 'targets_posted', 'error',
    ];

    protected function casts(): array
    {
        return [
            'post_date' => 'date',
            'dry_run' => 'boolean',
            'targets_posted' => 'array',
            'tried_content_ids' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ClipCampaign::class, 'campaign_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(MarketingClip::class, 'marketing_clip_id');
    }

    public function markFailed(string $reason): void
    {
        $this->update(['status' => 'failed', 'error' => mb_substr($reason, 0, 250)]);
    }
}
