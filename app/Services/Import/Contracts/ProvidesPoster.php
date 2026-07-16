<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\RemoteSeries;

/**
 * Optional capability: a source that can re-fetch a title's poster/cover URL on demand. Used by
 * [App\Support\PosterBackfill] to heal a title whose stored (hotlinked) poster has gone dead.
 */
interface ProvidesPoster
{
    /** A fresh poster URL for this title, or null if none can be found. */
    public function fetchPoster(RemoteSeries $series): ?string;
}
