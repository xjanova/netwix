<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Episode extends Model
{
    protected $fillable = [
        'content_id', 'season_id', 'number', 'title',
        'description', 'duration_minutes', 'video_url', 'thumbnail_path', 'sort',
    ];

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
