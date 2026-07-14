<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Mission extends Model
{
    protected $fillable = [
        'title', 'description', 'video_source', 'video_ref', 'poster',
        'required_seconds', 'reward_kind', 'reward_amount', 'repeat', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'required_seconds' => 'integer',
            'reward_amount' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort')->orderByDesc('id');
    }

    public function isYoutube(): bool
    {
        return $this->video_source === 'youtube';
    }

    /** The player URL/id the front-end uses: a YT id passes through, a URL is the direct stream. */
    public function playRef(): string
    {
        return (string) $this->video_ref;
    }

    public function rewardLabel(): string
    {
        return $this->reward_kind === 'gold'
            ? $this->reward_amount.' เหรียญทอง'
            : $this->reward_amount.' เหรียญเงิน';
    }
}
