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

    'frontend' => [
        // Public storefront SPA — used to build device-pairing verification links.
        'client_url' => env('CLIENT_FRONTEND_URL', 'http://localhost:5173'),
    ],

    'cloudflare' => [
        // API token needs the Zone > Cache Purge permission for this zone.
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'cache_api_token' => env('CLOUDFLARE_CACHE_API_TOKEN'),
    ],

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

        // Serve the resolved HLS playlist to the web client instead of Bunny's
        // iframe embed. Off by default: the iframe player performs MediaCage DRM
        // decryption, and our Shaka setup only carries license servers when
        // content_formats.meta.drm.servers is populated. Turn this on per
        // environment only after confirming DRM and CORS work end to end —
        // ad breaks and our own subtitle/quality controls depend on it.
        'web_native_playback' => env('BUNNY_WEB_NATIVE_PLAYBACK', false),

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
        'timeout' => (int) env('PAY_FILMOTECA_TIMEOUT', 60),
    ],

    // Apple In-App Purchase (StoreKit 2, iOS credit packs). See docs/ios-in-app-purchase-audit.md.
    // Everything below is safe to leave unset for local `.storekit`-file development — only the
    // Sandbox/Production paths need real values, and those require the Paid Applications
    // Agreement to be Active before they can be used at all.
    'apple' => [
        'bundle_id' => env('APPLE_BUNDLE_ID', 'md.filmoteca.ios'),
        // Numeric App Store Connect app ID — required before Production redemptions can work.
        'app_apple_id' => env('APPLE_APP_APPLE_ID'),
        // Download from https://www.apple.com/certificateauthority/AppleRootCA-G3.cer (public,
        // no Apple account needed) and place at this path. Comma-separate multiple paths if
        // Apple ever rotates/adds a root.
        'root_certificate_paths' => array_filter(array_map('trim', explode(
            ',',
            env('APPLE_ROOT_CA_PATHS', storage_path('app/apple/AppleRootCA-G3.cer')),
        ))),
        'enable_online_checks' => (bool) env('APPLE_IAP_ENABLE_ONLINE_CHECKS', true),
        // In-App Purchase Key (.p8) contents, Key ID and Issuer ID — from App Store Connect →
        // Users and Access → Integrations → In-App Purchase. Needed only for the optional
        // CONSUMPTION_REQUEST response; redemption and refund handling work without them.
        'signing_key' => env('APPLE_IAP_SIGNING_KEY'),
        'key_id' => env('APPLE_IAP_KEY_ID'),
        'issuer_id' => env('APPLE_IAP_ISSUER_ID'),
    ],

];
