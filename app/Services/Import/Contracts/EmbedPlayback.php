<?php

namespace App\Services\Import\Contracts;

/**
 * Marker for a source whose playback is a THIRD-PARTY PLAYER IFRAME, not a stream NetWix can proxy.
 * 9nung ([NaayNungSource]) migrated to abyss/hydrax (2026-07-07): the real video URL is decrypted
 * client-side inside an obfuscated player, the source API is Cloudflare-gated, and the player
 * framebusts if opened directly — so there is no server-fetchable .m3u8/.mp4. The only way to play it
 * is to embed the player's own page in a sandboxed <iframe> (popups blocked) and let the browser do
 * the work, exactly as the source site does. resolveByRef() returns a RemoteStream::KIND_EMBED whose
 * `url` is that embed page; [EpisodeSourceController]/[AdminPreviewController] hand it to the front-end
 * to render an iframe instead of the HLS proxy.
 */
interface EmbedPlayback {}
