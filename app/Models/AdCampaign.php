<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A pre-roll ad campaign shown on the player before the video starts. See the migration for the schema.
 * The creative is either an uploaded file (`media_path`, public disk) or an external URL (`media_url`);
 * a YouTube link is rendered as an iframe, anything else plays in the <video> element / <img>.
 */
class AdCampaign extends Model
{
    protected $fillable = [
        'name', 'media_type', 'media_path', 'media_url', 'caption', 'link_url',
        'skippable', 'skip_after', 'image_seconds', 'target', 'target_type', 'target_genre_id',
        'frequency', 'hide_for_pro', 'is_active', 'starts_at', 'ends_at', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'skippable' => 'boolean',
            'hide_for_pro' => 'boolean',
            'is_active' => 'boolean',
            'skip_after' => 'integer',
            'image_seconds' => 'integer',
            'sort' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'target_genre_id');
    }

    /** Enabled AND inside its schedule window (null bounds = open-ended on that side). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** The playable/displayable URL: an uploaded file resolves via the public disk, else the raw URL. */
    public function getMediaSrcAttribute(): ?string
    {
        if ($this->media_path) {
            return Storage::url($this->media_path);   // same convention as Content::resolveMedia
        }

        return $this->media_url ?: null;
    }

    /** Non-null YouTube id when the creative is a YouTube link (rendered as an iframe on the overlay). */
    public function youtubeId(): ?string
    {
        return $this->media_type === 'video' && $this->media_url
            ? Content::youtubeIdFrom($this->media_url)
            : null;
    }

    /** True when there is something to actually show. */
    public function hasCreative(): bool
    {
        return (bool) ($this->media_path || $this->media_url);
    }

    /**
     * The single best campaign to pre-roll for this title + viewer, or null. Filters to active +
     * in-window + creative-present, drops hide_for_pro campaigns for Pro members, and matches the
     * target (all / this content type / one of the title's genres). Highest `sort` wins, then newest.
     */
    public static function pickFor(Content $content, ?User $user): ?self
    {
        $genreIds = $content->relationLoaded('genres')
            ? $content->genres->pluck('id')->all()
            : $content->genres()->pluck('genres.id')->all();

        $q = static::query()->active()
            ->where(fn ($w) => $w->whereNotNull('media_path')->orWhereNotNull('media_url'))
            ->where(function ($w) use ($content, $genreIds) {
                $w->where('target', 'all')
                    ->orWhere(fn ($t) => $t->where('target', 'type')->where('target_type', $content->type))
                    ->orWhere(fn ($t) => $t->where('target', 'genre')->whereIn('target_genre_id', $genreIds ?: [0]));
            });

        if ($user && $user->isProMember()) {
            $q->where('hide_for_pro', false);
        }

        return $q->orderByDesc('sort')->orderByDesc('id')->first();
    }

    /** Flatten to the small array the player overlay (partials/preroll-ad) consumes. */
    public function toPlayerPayload(): array
    {
        return [
            'id' => $this->id,
            'media_type' => $this->media_type,          // image | video
            'src' => $this->media_src,                    // resolved image/direct-video URL (null for YT)
            'youtube' => $this->youtubeId(),              // YT id when the creative is a YouTube link
            'caption' => $this->caption,
            'link_url' => $this->link_url,
            'skippable' => (bool) $this->skippable,
            'skip_after' => (int) $this->skip_after,
            'image_seconds' => max(3, (int) $this->image_seconds),
            'frequency' => $this->frequency ?: 'always',  // always | session | daily
        ];
    }
}
