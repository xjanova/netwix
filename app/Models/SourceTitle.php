<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceTitle extends Model
{
    protected $fillable = [
        'source', 'source_key', 'title', 'clean_title', 'description',
        'poster_url', 'year', 'dub_type', 'view_count', 'episodes_count',
        'extra', 'content_id', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'extra' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function scopeImported(Builder $q): Builder
    {
        return $q->whereNotNull('content_id');
    }

    public function scopeNotImported(Builder $q): Builder
    {
        return $q->whereNull('content_id');
    }

    public function displayTitle(): string
    {
        return $this->clean_title ?: $this->title;
    }

    public function dubLabel(): ?string
    {
        return match ($this->dub_type) {
            'thai_dub' => 'พากย์ไทย',
            'thai_sub' => 'ซับไทย',
            default => null,
        };
    }
}
