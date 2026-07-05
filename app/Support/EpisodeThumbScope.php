<?php

namespace App\Support;

use App\Models\Episode;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of "which episodes does a cover-generation batch cover".
 *
 * Shared by [App\Http\Controllers\Admin\ThumbController] (to snapshot the
 * denominator at begin) and [App\Jobs\SeedEpisodeThumbs] (to walk the cursor and
 * enqueue). Keeping it in one place means the count the admin sees and the set the
 * seeder actually queues can never drift apart.
 */
class EpisodeThumbScope
{
    /**
     * @param  array{scope?:string,genre_id?:int|null,content_id?:int|null,force?:bool}  $p
     */
    public static function query(array $p): Builder
    {
        $q = Episode::query()
            ->whereNotNull('source_ref')
            ->whereHas('content', fn ($c) => $c->where('is_published', true));

        // Skip episodes that already have a cover, unless we're regenerating.
        if (empty($p['force'])) {
            $q->whereNull('thumbnail_path');
        }

        $scope = $p['scope'] ?? 'all';
        if ($scope === 'genre' && ! empty($p['genre_id'])) {
            $q->whereHas('content.genres', fn ($g) => $g->where('genres.id', (int) $p['genre_id']));
        } elseif ($scope === 'title' && ! empty($p['content_id'])) {
            $q->where('content_id', (int) $p['content_id']);
        }

        return $q;
    }
}
