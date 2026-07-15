<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One marketing clip cut from a title/episode, destined for Facebook.
 *
 * The heavy lifting (download the source segments, ffmpeg cut/encode) happens in
 * [App\Jobs\GenerateMarketingClip] on the CLI queue — see [App\Support\ClipMaker].
 */
class MarketingClip extends Model
{
    protected $fillable = [
        'campaign_id', 'content_id', 'episode_id', 'start', 'start_mode', 'duration', 'aspect',
        'status', 'error', 'file_path', 'poster_path', 'file_size',
        'caption', 'platform', 'auto_post', 'post_targets', 'scheduled_at', 'posted_at',
        'remote_post_id', 'dry_run', 'batch_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'start' => 'integer',
            'duration' => 'integer',
            'auto_post' => 'boolean',
            'dry_run' => 'boolean',
            'scheduled_at' => 'datetime',
            'posted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** The campaign that produced this clip, if it was cut by the auto-post pipeline. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ClipCampaign::class, 'campaign_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /** Public URL of the finished mp4 (null until the job produces it). */
    public function getFileUrlAttribute(): ?string
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
    }

    /** Public URL of the poster still, falling back to the title poster. */
    public function getPosterUrlAttribute(): ?string
    {
        if ($this->poster_path) {
            return Storage::disk('public')->url($this->poster_path);
        }

        return $this->content?->poster_url;
    }

    public function getIsReadyAttribute(): bool
    {
        return $this->status === 'ready' && (bool) $this->file_path;
    }
}
