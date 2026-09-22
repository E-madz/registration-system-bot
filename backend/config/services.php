<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Telegram is unreachable without one from networks that block it
        // directly (e.g. from Iran without a VPN). Setting this makes every
        // Telegram API call go through the proxy regardless of which process
        // makes it (web server, queue worker, artisan command, ...) - unlike
        // a shell-exported http_proxy/https_proxy, which only covers
        // whichever process happened to inherit it.
        'proxy' => env('TELEGRAM_HTTP_PROXY'),
    ],

    'bale' => [
        'bot_token' => env('BALE_BOT_TOKEN'),
        'webhook_secret' => env('BALE_WEBHOOK_SECRET'),
    ],

    'rubika' => [
        'bot_token' => env('RUBIKA_BOT_TOKEN'),
        'webhook_secret' => env('RUBIKA_WEBHOOK_SECRET'),
    ],

];
