<?php

namespace App\Support;

/**
 * One cover offered by a by-name search on a source site (see [App\Services\Import\Contracts\
 * SearchesPosters]). Carries what a human needs to judge it — the title the site calls it, the image
 * itself, and the page it came from — plus the similarity score [PosterSearch] computed.
 */
class PosterCandidate
{
    public function __construct(
        /** Title as the SOURCE spells it — the thing the admin compares against ours. */
        public string $title,
        /** Absolute URL of the cover image. */
        public string $image,
        /** The source site's page for this title (so the admin can check it), if known. */
        public ?string $page = null,
        /** Which source answered — set by [PosterSearch], sources needn't fill it. */
        public string $source = '',
        /** 0..1 title similarity, filled in by [PosterSearch::rank]. */
        public float $score = 0.0,
    ) {}

    /** @return array{title:string,image:string,page:?string,source:string,score:float} */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'image' => $this->image,
            'page' => $this->page,
            'source' => $this->source,
            'score' => round($this->score, 3),
        ];
    }
}
