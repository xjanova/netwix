<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\RemoteSeries;

/**
 * Optional capability: a source that can scrape a title's real content genres on demand (mapped to
 * NetWix genre NAMES). Used by [App\Console\Commands\ScrapeGenres] to backfill sub-genres for sources
 * whose catalogue feed omits them (e.g. animeruka's Dooplay archive), so the per-genre browse rows
 * aren't empty once a sub-genred source is hidden.
 */
interface ProvidesGenres
{
    /** NetWix genre names for this title beyond the umbrella (e.g. ['แอ็กชัน','ผจญภัย']), or []. */
    public function fetchGenres(RemoteSeries $series): array;
}
