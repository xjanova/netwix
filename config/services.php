<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Desktop ingest bridge (Hive Download → NetWix). The desktop app authenticates
    // with this token to upload mirrored video files. Set NETWIX_INGEST_TOKEN in .env.
    'ingest' => [
        'token' => env('NETWIX_INGEST_TOKEN'),
        'max_gb' => (float) env('NETWIX_MEDIA_MAX_GB', 100),
    ],

    // ---- Social sign-in (requires laravel/socialite on the server) --------
    // Google:  https://console.cloud.google.com/apis/credentials
    // LINE:    https://developers.line.biz/console  (LINE Login channel)
    //          + composer require socialiteproviders/line
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'line' => [
        'client_id' => env('LINE_CLIENT_ID'),
        'client_secret' => env('LINE_CLIENT_SECRET'),
        'redirect' => env('LINE_REDIRECT_URI', '/auth/line/callback'),
    ],

    // Help centre = chat with an admin via the NetWix LINE Official Account.
    'support' => [
        'line_url' => env('SUPPORT_LINE_URL', 'https://line.me/R/ti/p/@netwix'),
        'email' => env('SUPPORT_EMAIL', 'support@netwix.online'),
    ],

    // ffmpeg (static build on the box) — grabs a frame as the episode cover.
    'ffmpeg' => [
        'bin' => env('FFMPEG_BIN', '/home/admin/bin/ffmpeg'),
    ],

];
