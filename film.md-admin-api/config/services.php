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
        // === REQUIRED ===
        // Stream API base (constant, don't change)
        'stream_base_url' => env('BUNNY_STREAM_BASE_URL', 'https://video.bunnycdn.com'),
        'stats_api_base' => env('BUNNY_STATS_API_BASE', 'https://video.bunnycdn.com'),

        // Token Authentication Key (Library → Settings → Security)
        // Used to sign HLS playback URLs so only authorized viewers can play.
        'token_key' => env('BUNNY_STREAM_TOKEN_KEY'),

        // Webhook secret (Library → Webhooks → Secret) — used to validate
        // HMAC-SHA256 signature on incoming webhook events.
        'webhook_secret' => env('BUNNY_WEBHOOK_SECRET'),

        // Per-library API Keys (Library → Settings → API).
        // Filmoteca uses 2 libraries: movies (full feature films, DRM) and
        // trailers (short previews, no DRM). The right key is auto-selected
        // by BunnyLibraryResolver based on content_format.format_type.
        // library_id is NOT stored here — each content_format row carries its
        // own bunny_library_id, set when the film is added in admin.
        'libraries' => [
            'movies' => [
                'api_key' => env('MOVIES_BUNNY_API_KEY'),
            ],
            'trailers' => [
                'api_key' => env('TRAILERS_BUNNY_API_KEY'),
            ],
        ],

        // Default Stream API key for legacy/fallback paths. Resolves to the
        // movies library key when the format doesn't specify a kind.
        'stream_api_key' => env('BUNNY_STREAM_API_KEY', env('MOVIES_BUNNY_API_KEY')),

        // === OPTIONAL (only if you want global CDN dashboard) ===
        // Account API Key (Bunny Dashboard → Account → API).
        // Needed only for pull-zone-level stats (total bandwidth, cache hit
        // rate). Per-video bandwidth comes from Stream library stats already.
        'account_api_key' => env('BUNNY_ACCOUNT_API_KEY'),
        'cdn_api_key' => env('BUNNY_CDN_API_KEY', env('BUNNY_ACCOUNT_API_KEY')),
        'cdn_pull_zone_id' => env('BUNNY_CDN_PULL_ZONE_ID'),
        'cdn_api_base' => env('BUNNY_CDN_API_BASE', 'https://api.bunny.net'),

        // === NOT NEEDED for filmoteca ===
        // Storage Zone is only used when uploading raw files via API.
        // Filmoteca uploads videos directly through Bunny dashboard, so these
        // can stay empty. Kept here for completeness if you ever script bulk
        // poster uploads or migrate to direct uploads.
        'storage_zone_name' => env('BUNNY_STORAGE_ZONE_NAME'),
        'storage_api_key' => env('BUNNY_STORAGE_API_KEY'),
    ],

    'pay_filmoteca' => [
        'base_url' => env('PAY_FILMOTECA_BASE_URL', 'https://pay.filmoteca.md'),
        'username' => env('PAY_FILMOTECA_USERNAME'),
        'password' => env('PAY_FILMOTECA_PASSWORD'),
        'api_key' => env('PAY_FILMOTECA_API_KEY'),
        'callback_url' => env('PAY_FILMOTECA_CALLBACK_URL'),
        'success_url' => env('PAY_FILMOTECA_SUCCESS_URL'),
        'failed_url' => env('PAY_FILMOTECA_FAILED_URL'),
        'timeout' => (int) env('PAY_FILMOTECA_TIMEOUT', 20),
    ],

];
