<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissionCompletion extends Model
{
    protected $fillable = ['user_id', 'mission_id', 'day', 'reward_kind', 'reward_amount', 'completed_at'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'completed_at' => 'datetime',
            'reward_amount' => 'integer',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
