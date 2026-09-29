<?php

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

/**
 * rongyok blocks this server's IP range and Cloudflare Worker egress (since 2026-09-28 22:00, verified
 * by swapping only the exit IP under the same client both ways). When services.rongyok.relay_url is
 * set, requests to rongyok.com are re-pointed at a relay on a residential line, which forwards the
 * path, query, UA and Referer unchanged. Requests to any other host pass through untouched, so this is
 * safe to apply to a client that fetches from many sources.
 */
final class RongYokRelay
{
    public const HOST = 'rongyok.com';

    public static function apply(PendingRequest $req): PendingRequest
    {
        $relay = rtrim((string) config('services.rongyok.relay_url'), '/');
        if ($relay === '') {
            return $req;
        }
        $to = new Uri($relay);
        $key = (string) config('services.rongyok.relay_key');

        return $req->withRequestMiddleware(function (RequestInterface $r) use ($to, $key): RequestInterface {
            if ($r->getUri()->getHost() !== self::HOST) {
                return $r;
            }

            return $r->withUri($r->getUri()->withScheme($to->getScheme())->withHost($to->getHost())->withPort($to->getPort()))
                ->withHeader('X-Relay-Key', $key);
        });
    }
}
