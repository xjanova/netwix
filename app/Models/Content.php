<?php

namespace App\Models;

use App\Models\Scopes\MaturityScope;
use App\Support\Maturity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Content extends Model
{
    protected static function booted(): void
    {
        // Kids profiles never see adult (18+/20+) titles, anywhere (listings + direct URL binding).
        static::addGlobalScope(new MaturityScope);
    }

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

    /**
     * Rank by an engagement score so comments / ratings / likes actually move the
     * charts, not just raw view count. Weights: views + likes×10 + comments×5 +
     * avgStars×20.
     */
    public function scopeRankedByEngagement(Builder $q): Builder
    {
        return $q->withCount(['likedBy', 'comments'])
            ->withAvg('ratings', 'stars')
            ->orderByRaw('(views + liked_by_count * 10 + comments_count * 5 + COALESCE(ratings_avg_stars, 0) * 20) desc');
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

    /**
     * Normalised title key for duplicate detection: lowercase, dub/quality tags and all non-word
     * characters stripped, so "Perfect World (พากย์ไทย) HD" and "perfect world" collapse to one key.
     * Deliberately loose — a match means "likely the same title", to be surfaced for admin review.
     */
    public static function dedupeKey(?string $title): string
    {
        $t = mb_strtolower(trim((string) $title), 'UTF-8');
        $t = preg_replace('~พากย์ไทย|ซับไทย|ซับ|พากย์|เต็มเรื่อง|ตอนจบ|ครบทุกตอน|the\s*movie|hd~u', ' ', $t) ?? $t;
        $t = preg_replace('~[^\p{L}\p{N}]+~u', '', $t) ?? $t;   // drop spaces & punctuation

        return $t;
    }

    public function getMatchLabelAttribute(): string
    {
        return $this->match_score.'% ตรงใจ';
    }

    /** Adult rating (18+/20+): adults-only + Pro-gated. */
    public function getIsAdultAttribute(): bool
    {
        return Maturity::isAdult($this->maturity);
    }

    /** Whether watching this title needs a Pro membership (adult ratings do). */
    public function getRequiresProAttribute(): bool
    {
        return $this->is_adult;
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

    /**
     * Per-title SEO keyword string for the page <head> — the title with พากย์ไทย/ซับไทย/ดู…
     * variants (how Thai users actually search a specific show) plus its genres and a type label.
     * Consumed by the title/watch/vertical-player views via @section('meta_keywords', …).
     */
    public function getSeoKeywordsAttribute(): string
    {
        $typeLabel = match ($this->type) {
            'movie' => 'ดูหนัง',
            'vertical' => 'ซีรีส์แนวตั้ง',
            default => 'ดูซีรี่ย์',
        };

        // Admin-editable per-type keyword template (Setting) is appended to the auto-generated set.
        $template = (string) \App\Models\Setting::get('seo_kw_'.($this->type ?: 'series'), '');

        return collect([
            $this->title,
            $this->title.' พากย์ไทย',
            $this->title.' ซับไทย',
            'ดู'.$this->title,
            $typeLabel.$this->title,
        ])->merge($this->genres->pluck('name'))
            ->merge([$typeLabel, 'ดูฟรี HD', 'ดูซีรี่ย์ออนไลน์ฟรี'])
            ->merge($template !== '' ? array_map('trim', explode(',', $template)) : [])
            ->filter()->unique()->implode(', ');
    }
}
