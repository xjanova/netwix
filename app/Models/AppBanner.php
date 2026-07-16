<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A promo banner shown at the top of the mobile app's home screen. Creative is
 * an uploaded image (`image_path`, public disk) or an external URL (`image_url`)
 * — same convention as AdCampaign.
 */
class AppBanner extends Model
{
    protected $fillable = [
        'title', 'image_path', 'image_url', 'link_url',
        'hide_for_pro', 'is_active', 'starts_at', 'ends_at', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'hide_for_pro' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort' => 'integer',
        ];
    }

    /** Enabled AND inside its schedule window (null bounds = open-ended on that side). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /** The displayable URL: an uploaded file resolves via the public disk, else the raw URL. */
    public function getImageSrcAttribute(): ?string
    {
        if ($this->image_path) {
            return Storage::url($this->image_path);
        }

        return $this->image_url ?: null;
    }

    /**
     * Banners to show this viewer, in display order. Drops hide_for_pro
     * campaigns for Pro members and anything without a creative.
     */
    public static function forViewer(?User $user): \Illuminate\Support\Collection
    {
        $q = static::query()->active()
            ->where(fn ($w) => $w->whereNotNull('image_path')->orWhereNotNull('image_url'));

        if ($user && $user->isProMember()) {
            $q->where('hide_for_pro', false);
        }

        return $q->orderByDesc('sort')->orderByDesc('id')->limit(8)->get();
    }

    /** Flatten to the payload the app consumes. */
    public function toAppPayload(): array
    {
        // The app needs an absolute URL (Storage::url is site-relative for local disk).
        $src = $this->image_src;
        if ($src !== null && str_starts_with($src, '/')) {
            $src = rtrim(config('app.url'), '/').$src;
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'image' => $src,
            'link_url' => $this->link_url,
        ];
    }
}
