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
        'title', 'slug', 'source', 'source_key', 'type', 'synopsis', 'year', 'maturity', 'dub_type',
        'match_score', 'rating', 'is_original', 'is_featured', 'is_published', 'is_vip', 'vip_price_gold',
        'suspended_at', 'suspend_reason', 'playback_fail_count', 'review_flagged_at', 'review_ignored',
        'poster_path', 'backdrop_path', 'trailer_youtube_id', 'video_url',
        'duration_minutes', 'views', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_original' => 'boolean',
            'is_featured' => 'boolean',
            'is_published' => 'boolean',
            'is_vip' => 'boolean',
            'suspended_at' => 'datetime',
            'review_flagged_at' => 'datetime',
            'review_ignored' => 'boolean',
            'rating' => 'decimal:1',
        ];
    }

    /** A live title whose link is flagged for review (viewers failed to play it) and not yet OK'd. */
    public function getLinkUnderReviewAttribute(): bool
    {
        return $this->review_flagged_at !== null && ! $this->review_ignored && $this->suspended_at === null;
    }

    /** Auto-suspended (un-playable) titles parked for admin review. */
    public function scopeSuspended(Builder $q): Builder
    {
        return $q->whereNotNull('suspended_at');
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

    /**
     * Titles safe to expose on the PUBLIC (crawlable, guest-visible) surface: published, not
     * auto-suspended, and never adult (18+/20+). Guests have no profile, so MaturityScope can't hide
     * adult content for them — this scope is the hard gate that keeps adult titles off public pages,
     * the sitemap and search-engine crawls. Use it for every guest-facing listing/query.
     */
    public function scopePublicListing(Builder $q): Builder
    {
        return $q->where('is_published', true)
            ->whereNull('suspended_at')
            ->where(fn ($w) => $w->whereNull('maturity')->orWhereNotIn('maturity', Maturity::ADULT));
    }

    public function scopeType(Builder $q, string $type): Builder
    {
        return $q->where('type', $type);
    }

    /**
     * "มาแรง" ranking — hottest by raw VIEW COUNT first (owner: หนังมาแรงดูจากยอดวิวเป็นหลัก).
     * Rating then id only break ties, so a brand-new 0-view title still orders by its star rating
     * rather than insertion order. (Was an engagement composite; views now lead outright.)
     */
    public function scopeTrending(Builder $q): Builder
    {
        return $q->orderByDesc('views')->orderByDesc('rating')->orderByDesc('id');
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

    /**
     * Human-readable TITLE view count for the card + detail. Compact above 1K (1.2K / 3.4M) so it fits
     * the poster footer; "ยังไม่มีคนดู" when nobody has watched yet. `views` increments on every watch
     * (WatchController / Api\App\CatalogController), so 0 genuinely means un-watched.
     */
    public function getViewsLabelAttribute(): string
    {
        $v = (int) $this->views;
        if ($v <= 0) {
            return 'ยังไม่มีคนดู';
        }
        if ($v < 1000) {
            return number_format($v).' ครั้ง';
        }
        // ≥ 999,500 rounds to 1.0M, so promote to the M unit (avoids an ugly "1000K").
        [$n, $unit] = $v >= 999_500 ? [$v / 1_000_000, 'M'] : [$v / 1000, 'K'];
        $s = $n >= 100 ? (string) round($n) : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');

        return $s.$unit.' ครั้ง';
    }

    /** Adult rating (18+/20+): adults-only + Pro-gated. */
    public function getIsAdultAttribute(): bool
    {
        return Maturity::isAdult($this->maturity);
    }

    /** Thai audio/subtitle label for the card + detail badge (null when unknown). */
    public function getDubLabelAttribute(): ?string
    {
        return match ($this->dub_type) {
            'thai_dub' => 'พากย์ไทย',
            'thai_sub' => 'ซับไทย',
            default => null,
        };
    }

    /**
     * Best-effort dub/sub from a Thai title when the source didn't tell us — most catalogue titles
     * carry a "พากย์ไทย"/"ซับไทย" tag right in the name. Prefers พากย์ (dub) when both appear.
     */
    public static function guessDubType(?string $title): ?string
    {
        $t = mb_strtolower((string) $title, 'UTF-8');
        if (mb_strpos($t, 'พากย์') !== false) {
            return 'thai_dub';
        }
        if (mb_strpos($t, 'ซับ') !== false) {
            return 'thai_sub';
        }

        return null;
    }

    /** Whether watching this title needs a Pro membership (adult ratings do). */
    public function getRequiresProAttribute(): bool
    {
        return $this->is_adult;
    }

    /** Number of days a freshly-imported title keeps its "มาใหม่" badge. */
    public const NEW_BADGE_DAYS = 7;

    /** True for the first {NEW_BADGE_DAYS} days after a title was added (created_at = first import). */
    public function getIsNewAttribute(): bool
    {
        return $this->created_at !== null
            && $this->created_at->greaterThan(now()->subDays(self::NEW_BADGE_DAYS));
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
