<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Psr\Http\Message\RequestInterface;

/**
 * Optional server proxy, restricted to the source host. No residential-machine relay.
 */
final class RongYokTransport
{
    public const HOST = 'rongyok.com';

    public static function apply(PendingRequest $req): PendingRequest
    {
        $proxy = RongYokClientResolver::proxyUrl();
        if ($proxy !== '') {
            // Apply only to the source host, including on multi-source poster clients.
            return $req->withMiddleware(function (callable $handler) use ($proxy) {
                return function (RequestInterface $r, array $options) use ($handler, $proxy) {
                    if ($r->getUri()->getHost() === self::HOST) {
                        $options['proxy'] = $proxy;
                        $options['connect_timeout'] = 3;
                        $options['timeout'] = 7;
                    }

                    return $handler($r, $options);
                };
            });
        }

        return $req;
    }
}
