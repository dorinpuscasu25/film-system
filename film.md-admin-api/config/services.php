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

    'bunny' => [
        'stream_base_url' => env('BUNNY_STREAM_BASE_URL'),
        'token_key' => env('BUNNY_STREAM_TOKEN_KEY'),
        'webhook_secret' => env('BUNNY_WEBHOOK_SECRET'),
        'stream_api_key' => env('BUNNY_STREAM_API_KEY'),
        'cdn_api_key' => env('BUNNY_CDN_API_KEY'),
        'cdn_pull_zone_id' => env('BUNNY_CDN_PULL_ZONE_ID'),
        'storage_zone_name' => env('BUNNY_STORAGE_ZONE_NAME'),
        'storage_api_key' => env('BUNNY_STORAGE_API_KEY'),
        'stats_api_base' => env('BUNNY_STATS_API_BASE', 'https://api.bunny.net'),
    ],

];
