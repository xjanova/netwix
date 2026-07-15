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

            // Effective playback markers (0 = unset), already merged with the
            // title's defaults so the client never re-implements the rule.
            'intro_end_seconds' => $this->marker('intro_end_seconds'),
            'outro_seconds' => $this->marker('outro_seconds'),
        ];
    }

    /**
     * A NULL marker on an episode means "inherit the title's value" — the same
     * rule the web player applies. The caller sets the `content` relation, so
     * this resolves without a query; an unset relation just falls back to 0.
     */
    private function marker(string $key): int
    {
        if ($this->resource->{$key} !== null) {
            return (int) $this->resource->{$key};
        }

        $content = $this->resource->relationLoaded('content') ? $this->resource->content : null;

        return (int) ($content?->{$key} ?? 0);
    }
}
