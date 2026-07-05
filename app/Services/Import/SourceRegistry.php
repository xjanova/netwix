<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Sources\HalimSource;
use App\Services\Import\Sources\HalimSites;
use App\Services\Import\Sources\RongYokSource;
use App\Services\Import\Sources\WowDramaSource;

class SourceRegistry
{
    /** @var array<string,MediaSource> */
    private array $sources = [];

    public function __construct()
    {
        $sources = [new RongYokSource, new WowDramaSource];
        // Every Halim-theme site (24-hdx, anime108, …) is the same engine + a config — see [HalimSites].
        foreach (HalimSites::all() as $config) {
            $sources[] = new HalimSource($config);
        }

        foreach ($sources as $source) {
            $this->sources[$source->id()] = $source;
        }
    }

    /** @return array<string,MediaSource> */
    public function all(): array
    {
        return $this->sources;
    }

    public function get(string $id): ?MediaSource
    {
        return $this->sources[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    /**
     * The Halim sites flagged as an independent backup pool (own catalogue + own player CDN), used by
     * [App\Support\BackupFinder] to re-source an un-playable title from another site.
     *
     * @return array<string,HalimSource>
     */
    public function backupPool(): array
    {
        return array_filter(
            $this->sources,
            fn (MediaSource $s) => $s instanceof HalimSource && $s->isBackupPool(),
        );
    }
}
