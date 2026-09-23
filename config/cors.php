<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Published to REPLACE Laravel's default, which answers every `api/*` request with
| `Access-Control-Allow-Origin: *`. That let any website call our stream resolver
| (`/api/episode/{id}/source`, `/api/app/episodes/{id}/source`) from its own visitors' browsers and
| hand them our links — the load spread over their IPs, so no per-IP limit ever saw it.
|
| Nothing legitimate needs cross-origin access: our pages are same-origin, and the mobile app is a
| native client, which CORS does not apply to at all. Only our own origin is listed.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [rtrim((string) env('APP_URL', 'https://netwix.online'), '/')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
