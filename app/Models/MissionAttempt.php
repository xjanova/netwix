<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionAttempt extends Model
{
    protected $fillable = ['user_id', 'mission_id', 'token', 'started_at', 'last_beat_at', 'watched_seconds', 'awarded_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_beat_at' => 'datetime',
            'awarded_at' => 'datetime',
            'watched_seconds' => 'integer',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }
}
