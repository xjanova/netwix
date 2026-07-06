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

    // ffmpeg (static build on the box) — grabs a frame as the episode cover AND cuts
    // marketing clips (App\Support\ClipMaker).
    //   font     — absolute path to a .ttf that supports Thai (e.g. a Noto/Sarabun file
    //              uploaded to the box). Only when set + present does ClipMaker burn the
    //              CTA onto the clip; otherwise it degrades to no overlay (never breaks).
    //   clip_cta — the burned-in call-to-action text on each clip.
    'ffmpeg' => [
        'bin' => env('FFMPEG_BIN', '/home/admin/bin/ffmpeg'),
        'font' => env('FFMPEG_FONT', ''),
        'clip_cta' => env('FFMPEG_CLIP_CTA', 'ดูเต็มเรื่องฟรี · แอป NetWix'),
    ],

    // AI caption writer for marketing clips (App\Support\CaptionWriter). The LLM only
    // writes the creative hook; the CTA line, app link and hashtags are always appended
    // deterministically by code so they can never be wrong or missing.
    //   driver   — 'groq' | 'openai' (any OpenAI-compatible chat API) | 'template' (no key,
    //              a solid rotating template — the graceful default so Phase 2 works today).
    //   app_url  — the "download the app" link put in every caption; falls back to /download.
    'caption' => [
        'driver' => env('CAPTION_DRIVER', 'template'),
        'api_key' => env('CAPTION_API_KEY', ''),
        'model' => env('CAPTION_MODEL', 'llama-3.3-70b-versatile'),
        'base_url' => env('CAPTION_BASE_URL', 'https://api.groq.com/openai/v1'),
        'app_url' => env('CAPTION_APP_URL', ''),
        'hashtags' => env('CAPTION_HASHTAGS', '#หนังฟรี #ดูหนังออนไลน์ #NetWix #ซีรีส์ #หนังใหม่'),
        'lucky_line' => env('CAPTION_LUCKY_LINE', 'โหลดแอปเลย ดูฟรียาวๆ + ลุ้นรับโชคทุกสัปดาห์ 🎁'),
    ],

];
