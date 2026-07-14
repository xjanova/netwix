<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'avatar_color',
        'avatar_path',
        'is_kids',
    ];

    protected function casts(): array
    {
        return [
            'is_kids' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function myList(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'my_list_items')->withTimestamps();
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'likes')->withTimestamps();
    }

    public function watchProgress(): HasMany
    {
        return $this->hasMany(WatchProgress::class);
    }

    /** First grapheme of the name, for the avatar tile. */
    public function getInitialAttribute(): string
    {
        return mb_substr(trim($this->name), 0, 1);
    }

    /** Uploaded avatar as a URL, or null → the UI falls back to the coloured initial tile. */
    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return str_starts_with($this->avatar_path, 'http')
            ? $this->avatar_path
            : \Illuminate\Support\Facades\Storage::url($this->avatar_path);
    }
}
