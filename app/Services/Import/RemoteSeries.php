<?php

namespace App\Services\Import;

/** A series/title as returned by a remote source's catalogue. */
class RemoteSeries
{
    public function __construct(
        public string $source,
        public string $sourceKey,        // remote id (rongyok) or slug (wow-drama)
        public string $title,
        public string $cleanTitle,
        public ?string $description = null,
        public ?string $posterUrl = null,
        public ?int $year = null,
        public ?string $dubType = null,  // thai_dub | thai_sub | null
        public int $viewCount = 0,
        public array $extra = [],        // slug, jpg_url, …
    ) {}
}
