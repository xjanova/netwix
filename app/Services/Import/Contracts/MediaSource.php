<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\RemoteSeries;
use App\Services\Import\RemoteStream;

interface MediaSource
{
    /** Stable id, e.g. "rongyok" / "wowdrama". */
    public function id(): string;

    public function displayName(): string;

    /** The NetWix content type imported titles default to (series|movie|vertical). */
    public function defaultContentType(): string;

    /**
     * True if this source serves a progressive MP4 that's worth caching a local ep-1 preview for.
     * HLS sources stream through the server-side proxy instead and need no stored preview file.
     */
    public function isProgressive(): bool;

    /** An umbrella genre every title from this source is always filed under (e.g. "อนิเมะ"), or null. */
    public function umbrellaGenre(): ?string;

    /**
     * Fetch the remote catalogue, invoking $onBatch(RemoteSeries[]) per page/chunk so callers
     * can persist incrementally (a timeout still keeps earlier pages). Returns total emitted.
     */
    public function fetchCatalog(callable $onBatch, int $maxPages = 100): int;

    /**
     * Episode list for a series, in on-screen order.
     *
     * @return array<int,array{number:int,ref:string}>
     */
    public function fetchEpisodes(RemoteSeries $series): array;

    /** Resolve a fresh playable stream from stored keys (URLs expire, so this runs at watch time). */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream;
}
