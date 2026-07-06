<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Episode extends Model
{
    /** Give up mirroring an episode after this many failed attempts (source likely deleted it). */
    public const MIRROR_MAX_ATTEMPTS = 5;

    protected $fillable = [
        'content_id', 'season_id', 'source', 'source_ref', 'number', 'title',
        'backup_source', 'backup_key', 'backup_ref', 'backup_forced',
        'description', 'duration_minutes', 'video_url', 'thumbnail_path', 'sort',
        'mirrored_at', 'file_size', 'mirror_requested_at', 'mirror_requests', 'mirror_trigger',
        'mirror_attempts', 'mirror_failed_at', 'views',
    ];

    protected function casts(): array
    {
        return [
            'mirrored_at' => 'datetime',
            'mirror_requested_at' => 'datetime',
            'mirror_failed_at' => 'datetime',
            'views' => 'integer',
            'backup_forced' => 'boolean',
        ];
    }

    public function getIsUnavailableAttribute(): bool
    {
        return $this->mirror_attempts >= self::MIRROR_MAX_ATTEMPTS && $this->mirrored_at === null;
    }

    /** rongyok episode a customer asked for that isn't mirrored yet (server can't fetch it directly). */
    public function getIsPendingCustomerAttribute(): bool
    {
        return $this->mirror_requested_at !== null && $this->mirrored_at === null;
    }

    /** True once the video has been mirrored to our own storage (no longer depends on the source). */
    public function getIsMirroredAttribute(): bool
    {
        return $this->mirrored_at !== null;
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** Per-episode cover: the captured frame if we have one, else the title's main poster. */
    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->thumbnail_path) {
            return str_starts_with($this->thumbnail_path, 'http')
                ? $this->thumbnail_path
                : Storage::url($this->thumbnail_path);
        }

        return $this->content?->poster_url;   // fall back to the main poster until a frame is grabbed
    }

    public function getHasThumbAttribute(): bool
    {
        return (bool) $this->thumbnail_path;
    }

    public function getDurationLabelAttribute(): ?string
    {
        return $this->duration_minutes ? $this->duration_minutes.' นาที' : null;
    }
}
