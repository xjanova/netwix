<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Episode shape for the mobile app. Never exposes video_url / source /
 * source_ref / mirror internals — the client resolves playback via
 * GET /api/app/episodes/{episode}/source.
 */
class EpisodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_id' => $this->content_id,
            'season_id' => $this->season_id,
            'number' => (int) $this->number,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'thumbnail_url' => $this->thumbnail_url,
            'is_mirrored' => (bool) $this->is_mirrored,
            'is_unavailable' => (bool) $this->is_unavailable,
            'sort' => (int) $this->sort,
        ];
    }
}
