<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Content extends Model
{
    protected $fillable = [
        'title', 'slug', 'source', 'source_key', 'type', 'synopsis', 'year', 'maturity',
        'match_score', 'rating', 'is_original', 'is_featured', 'is_published',
        'poster_path', 'backdrop_path', 'trailer_youtube_id', 'video_url',
        'duration_minutes', 'views', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_original' => 'boolean',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'rating' => 'decimal:1',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ---- Relationships -------------------------------------------------

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'content_genre')->withPivot('is_primary');
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class)->orderBy('number');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('season_id')->orderBy('number');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    /** Profiles that liked this title (inverse of Profile::likes). */
    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'likes')->withTimestamps();
    }

    /** The first episode — its stored ep1 clip powers the browse hover preview. */
    public function previewEpisode(): HasOne
    {
        return $this->hasOne(Episode::class)->orderBy('season_id')->orderBy('number');
    }

    // ---- Scopes --------------------------------------------------------

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    public function scopeType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    // ---- Accessors -----------------------------------------------------

    public function primaryGenre(): ?Genre
    {
        return $this->genres->firstWhere('pivot.is_primary', true) ?? $this->genres->first();
    }

    public function getPosterUrlAttribute(): ?string
    {
        return $this->resolveMedia($this->poster_path);
    }

    public function getBackdropUrlAttribute(): ?string
    {
        return $this->resolveMedia($this->backdrop_path);
    }

    /**
     * URL for the silent hover preview on browse cards. Only our own locally
     * stored progressive MP4 (the downloaded episode 1) qualifies — remote CDN
     * links and HLS (.m3u8) can't autoplay in a bare <video>, so they're skipped
     * and the card falls back to the animated logo clip.
     */
    public function getPreviewUrlAttribute(): ?string
    {
        $ep = $this->relationLoaded('previewEpisode')
            ? $this->getRelation('previewEpisode')
            : $this->previewEpisode;

        $url = $ep?->video_url;

        if ($url && str_contains($url, '/storage/') && str_ends_with($url, '.mp4')) {
            return $url;
        }

        return null;
    }

    private function resolveMedia(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::url($path);
    }

    /** Deterministic gradient used for poster/backdrop placeholders (mirrors the theme). */
    public function getGradientAttribute(): string
    {
        $hue = crc32($this->slug ?: $this->title) % 360;

        return "linear-gradient(155deg, hsl({$hue} 55% 26%) 0%, hsl(".(($hue + 40) % 360)." 60% 14%) 100%)";
    }

    public function getMatchLabelAttribute(): string
    {
        return $this->match_score.'% ตรงใจ';
    }

    /** Best-effort YouTube id from either the trailer field or a video_url. */
    public function getYoutubeIdAttribute(): ?string
    {
        if ($this->trailer_youtube_id) {
            return $this->trailer_youtube_id;
        }

        return static::youtubeIdFrom($this->video_url);
    }

    public static function youtubeIdFrom(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([\w-]{11})~', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/^[\w-]{11}$/', $url)) {
            return $url;
        }

        return null;
    }
}
