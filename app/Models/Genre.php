<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genre extends Model
{
    protected $fillable = ['name', 'slug', 'sort'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_genre')->withPivot('is_primary');
    }
}
