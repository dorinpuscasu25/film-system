<?php

$configuredOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$defaultOrigins = array_values(array_unique(array_filter([
    env('CLIENT_FRONTEND_URL'),
    env('ADMIN_FRONTEND_URL'),
    'http://localhost:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:5174',
    'https://filmmd-api.veezify.com',
    'https://filmoteca.md',
    'https://www.filmoteca.md',
])));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $configuredOrigins !== [] ? $configuredOrigins : $defaultOrigins,

    'allowed_origins_patterns' => [
        '#^https?://localhost(?::\d+)?$#',
        '#^https?://127\.0\.0\.1(?::\d+)?$#',
        '#^https://([a-z0-9-]+\.)?veezify\.com$#i',
        '#^https://([a-z0-9-]+\.)?filmoteca\.md$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
