<?php

namespace App\Services\Import\Contracts;

use App\Support\PosterCandidate;

/**
 * Optional capability: a source that can be SEARCHED BY TITLE for a cover.
 *
 * This is the last resort, after [ProvidesPoster]. That one re-opens the title's own page and reads
 * its og:image, which needs a page we can still reach — useless for the two cases that actually make
 * up our missing covers: a title imported without any poster URL at all (37 of 24-hdx's), and a title
 * whose whole SOURCE has since gone dark (anifume's 183, anime108's 28), where nothing on the origin
 * site will ever answer again.
 *
 * Searching by name fixes both, and it does not have to be the title's own source — a cover found on
 * a live site for the same film is the same cover. Because the match is now made on a NAME rather
 * than on a stored id, a result is only ever a CANDIDATE: [App\Support\PosterSearch] scores it and
 * the admin confirms it. A wrong cover is worse than the branded fallback.
 *
 * @see \App\Support\PosterSearch
 */
interface SearchesPosters
{
    /**
     * Titles on this site matching $title, best-first, as poster candidates.
     *
     * @return PosterCandidate[]
     */
    public function searchPosters(string $title, int $limit = 8): array;
}
