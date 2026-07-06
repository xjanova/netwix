<?php

namespace App\Services\Import\Contracts;

use App\Services\Import\RemoteStream;

/**
 * A source eligible for the backup/force-link pool: it has its own catalogue AND its own player CDN,
 * so it can supply a fallback (auto) or forced (manual) stream for a title on another source. Kept
 * separate from [MediaSource] because the pool only needs "resolve a stream + is it eligible" — the
 * Halim sites and the Dooplay/fembed site ([NaayNungSource]) satisfy this without sharing an engine.
 *
 * Used by [App\Services\Import\SourceRegistry::backupPool], [App\Support\BackupFinder] and the manual
 * [App\Http\Controllers\Admin\ForceLinkController].
 */
interface BackupPoolSource
{
    public function id(): string;

    public function displayName(): string;

    /** HLS (proxied) sources are false; a progressive-MP4 source would be true. Pool sites are HLS. */
    public function isProgressive(): bool;

    /** Whether this source is currently switched on as a pool member. */
    public function isBackupPool(): bool;

    /** Resolve a fresh playable stream from stored keys (URLs expire, so this runs at watch time). */
    public function resolveByRef(string $sourceKey, string $sourceRef, array $extra = []): ?RemoteStream;
}
