<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\RemoteSeries;

/**
 * Optional capability: a source that can fetch a plot synopsis for a title (usually a detail-page
 * scrape that isn't in the catalogue feed). ImportService fills it on import when present, and the
 * `netwix:backfill-synopsis` command backfills already-imported titles.
 */
interface ProvidesSynopsis
{
    public function fetchSynopsis(RemoteSeries $series): ?string;
}
