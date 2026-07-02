<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Content shape for the mobile app (/api/app/*). Images are pre-resolved to
 * absolute URLs via the model's poster_url / backdrop_url accessors (which
 * pass http(s) through or wrap a local storage path).
 */
class ContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'type' => $this->type,                 // series | movie | vertical
            'synopsis' => $this->synopsis,
            'year' => $this->year,
            'maturity' => $this->maturity,
            'rating' => (float) $this->rating,     // editorial score 0-10
            'match_score' => (int) $this->match_score,
            'is_original' => (bool) $this->is_original,
            'is_featured' => (bool) $this->is_featured,
            'poster_url' => $this->poster_url,
            'backdrop_url' => $this->backdrop_url,
            'trailer_youtube_id' => $this->trailer_youtube_id,
            'duration_minutes' => $this->duration_minutes,
            'views' => (int) $this->views,
            'episodes_count' => $this->whenCounted('episodes'),
            'genres' => $this->whenLoaded('genres', fn () => $this->genres->map(fn ($g) => [
                'name' => $g->name,
                'slug' => $g->slug,
            ])->values()),
        ];
    }
}
