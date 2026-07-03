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

    /** English label shown alongside the Thai genre name (bilingual UI); null if unmapped. */
    public function getNameEnAttribute(): ?string
    {
        return [
            'ดราม่า' => 'Drama', 'แอ็กชัน' => 'Action', 'แฟนตาซี & ไซไฟ' => 'Fantasy & Sci-Fi',
            'โรแมนติก' => 'Romance', 'สยองขวัญ' => 'Horror', 'ตลก' => 'Comedy',
            'อาชญากรรม' => 'Crime', 'ผจญภัย' => 'Adventure', 'อนิเมะ' => 'Anime', 'การ์ตูน' => 'Cartoon',
        ][$this->name] ?? null;
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_genre')->withPivot('is_primary');
    }
}
