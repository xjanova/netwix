<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    protected $fillable = ['content_id', 'profile_id', 'stars'];

    protected $casts = ['stars' => 'integer'];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
