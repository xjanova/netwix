<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-broadcast notification shown in the mobile app's inbox.
 * `category` maps to the app's per-topic mute toggles (see CATEGORIES).
 */
class AppNotification extends Model
{
    /** category key => Thai admin label. Keep keys in sync with the app's prefs. */
    public const CATEGORIES = [
        'new_content' => 'หนังมาใหม่',
        'news' => 'ข่าวจากทีมงาน',
        'other' => 'อื่น ๆ',
    ];

    protected $fillable = ['category', 'title', 'body', 'image_url', 'link_url', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /** Active + already published, newest first (what the app inbox shows). */
    public function scopeLive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('id');
    }

    /** Flatten to the payload the app consumes. */
    public function toAppPayload(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'image_url' => $this->image_url,
            'link_url' => $this->link_url,
            'published_at' => ($this->published_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
