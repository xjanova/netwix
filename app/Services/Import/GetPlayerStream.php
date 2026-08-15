<?php

namespace App\Services\Import;

use Illuminate\Http\Client\PendingRequest;

/**
 * The "GetPlayer" streaming platform, which sits behind more than one of our sources: wow-drama.com
 * embeds getplay-cdn.com and goseries4k.com embeds torbo007.com. They are the same product — both
 * embed pages title themselves "GetPlayer Video" and ship the same obfuscated initvpla-v1.js — so
 * they gain the same defences at roughly the same time, and had already drifted apart in our code by
 * being handled twice.
 *
 * The handshake, recovered from that script and verified end-to-end 2026-08-16:
 *
 *   POST {origin}/api/tokenplay      {"md5": "<32-hex embed id>", "parent": "<framing host>"}
 *     → {"token","expires","session_id","stream_id"}
 *   GET  {origin}/api/stream/{stream_id}/index.m3u8?token=&expires=&parent=&sid={session_id}
 *
 * Three things are easy to get wrong, and each of them is a silent whole-source outage:
 *  - the request names the embed id **`md5`**. `stream_id` is what comes BACK, and sending the embed
 *    id under that name earns `{"error":"Missing required parameters"}`;
 *  - the manifest path takes that returned 64-hex `stream_id`, NOT the 32-hex embed id. Passing the
 *    embed id — which used to work, and is what our wowdrama code did until now — answers
 *    `403 Invalid Secure ID`;
 *  - `parent` is signed INTO the token, so the same value must be repeated on the manifest URL.
 *
 * `expires` runs ~2h out, far longer than the 10 minutes StreamController caches a rewritten playlist
 * for, so a viewer never meets a stale token. Segments are TikTok-CDN URLs carrying their own
 * long-lived signatures and need no Referer.
 */
final class GetPlayerStream
{
    /**
     * Trade an embed id for a signed HLS manifest URL, or null if the platform won't mint one (stream
     * purged, or the contract moved again — the caller treats both as "this episode has no stream").
     *
     * @param  string  $origin  e.g. "https://getplay-cdn.com"
     * @param  string  $embedId  the 32-hex id from the embed URL
     * @param  string|null  $parent  framing host the token is bound to; defaults to $origin's own host,
     *                               which is what the player sends when the embed is opened directly
     */
    public static function manifest(PendingRequest $http, string $origin, string $embedId, ?string $parent = null): ?string
    {
        $origin = rtrim($origin, '/');
        $parent ??= (string) parse_url($origin, PHP_URL_HOST);

        try {
            $resp = $http->asJson()
                ->withHeaders([
                    'Referer' => $origin.'/embed/'.$embedId,
                    'Origin' => $origin,
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->post($origin.'/api/tokenplay', ['md5' => $embedId, 'parent' => $parent]);
        } catch (\Throwable) {
            return null;   // retry() rethrows a 4xx as an exception — a dead stream, not our problem
        }
        if (! $resp->ok()) {
            return null;
        }

        $d = $resp->json();
        if (! is_array($d)) {
            return null;
        }
        foreach (['stream_id', 'token', 'expires', 'session_id'] as $key) {
            if (blank($d[$key] ?? null)) {
                return null;
            }
        }

        return $origin.'/api/stream/'.$d['stream_id'].'/index.m3u8?'.http_build_query([
            'token' => $d['token'],
            'expires' => $d['expires'],
            'parent' => $parent,
            'sid' => $d['session_id'],
        ]);
    }
}
