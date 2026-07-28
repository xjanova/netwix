<?php

namespace App\Support;

/** One link in an episode's playback chain — see [MirrorRotation]. */
class MirrorLink
{
    /**
     * @param  ?int  $mirrorId  the [App\Models\ContentMirror] row this came from, or null for the
     *                          title's own source (and for the legacy forced backup_* triple)
     * @param  string  $source  registry id of the site to resolve on
     * @param  string  $key  that site's remote id/slug for the title
     * @param  string  $ref  the episode ref to resolve (episode number on a mirror, source_ref on the primary)
     * @param  bool  $primary  true for the title's own source — the one the catalogue row describes
     */
    public function __construct(
        public ?int $mirrorId,
        public string $source,
        public string $key,
        public string $ref,
        public bool $primary = false,
    ) {}

    /** De-dupe identity: the same site+title+episode is the same link however it entered the chain. */
    public function fingerprint(): string
    {
        return $this->source.'|'.$this->key.'|'.$this->ref;
    }
}
