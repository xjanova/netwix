<?php

namespace App\Services\Import;

use App\Services\Import\Contracts\MediaSource;
use App\Services\Import\Sources\RongYokSource;
use App\Services\Import\Sources\WowDramaSource;

class SourceRegistry
{
    /** @var array<string,MediaSource> */
    private array $sources = [];

    public function __construct()
    {
        foreach ([new RongYokSource, new WowDramaSource] as $source) {
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
}
