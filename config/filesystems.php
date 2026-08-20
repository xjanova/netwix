<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Cloudflare R2 — where mirrored video files live once we stop depending on a source's CDN.
         *
         * `region` is literally the string "auto": R2 has no regions in the S3 sense, and the SDK
         * refuses to sign without something there.
         *
         * `url` MUST be the bucket's custom domain (e.g. https://cdn.netwix.online), not the
         * r2.cloudflarestorage.com endpoint. The endpoint is the private S3 API and answers 401 to a
         * viewer; a custom domain is the only shape that is both publicly playable and sat behind
         * Cloudflare's cache — and a cache HIT never reaches R2, so it is also the shape that stops
         * read operations scaling with traffic. Leaving this unset would make
         * Storage::disk('r2')->url() hand out an unplayable URL that nothing in the code validates.
         *
         * `throw` is ON, unlike the public disk. A silent false from a failed upload would let an
         * episode be marked mirrored with no bytes behind it.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET', 'netwix-media'),
            'endpoint' => env('R2_ENDPOINT'),          // https://<account-id>.r2.cloudflarestorage.com
            'url' => env('R2_PUBLIC_URL'),             // https://cdn.netwix.online
            'use_path_style_endpoint' => true,
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
