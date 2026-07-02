<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Episode extends Model
{
    protected $fillable = [
        'content_id', 'season_id', 'source', 'source_ref', 'number', 'title',
        'description', 'duration_minutes', 'video_url', 'thumbnail_path', 'sort',
        'mirrored_at', 'file_size',
    ];

    protected function casts(): array
    {
        return [
            'mirrored_at' => 'datetime',
        ];
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

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        return str_starts_with($this->thumbnail_path, 'http')
            ? $this->thumbnail_path
            : Storage::url($this->thumbnail_path);
    }

    public function getDurationLabelAttribute(): ?string
    {
        return $this->duration_minutes ? $this->duration_minutes.' นาที' : null;
    }
}
