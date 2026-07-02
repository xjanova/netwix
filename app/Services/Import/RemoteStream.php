<?php

namespace App\Services\Import;

/** A resolved, playable stream for one episode. */
class RemoteStream
{
    public const KIND_MP4 = 'mp4';   // progressive, browser-playable
    public const KIND_HLS = 'hls';   // .m3u8

    public function __construct(
        public string $kind,
        public string $url,
        public ?string $referer = null,
    ) {}
}
