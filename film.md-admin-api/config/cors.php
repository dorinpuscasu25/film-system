<?php

use Illuminate\Support\Arr;

$configuredOrigins = array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
));

$frontendOrigins = array_filter([
    trim((string) env('CLIENT_FRONTEND_URL', '')),
    trim((string) env('ADMIN_FRONTEND_URL', '')),
]);

$fallbackOrigins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:3000',
    'http://localhost:3001',
];

$allowedOrigins = array_values(array_unique(array_filter(Arr::flatten([
    $configuredOrigins,
    $frontendOrigins,
]))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins !== [] ? $allowedOrigins : $fallbackOrigins,

    'allowed_origins_patterns' => [
        '^https?://([a-z0-9-]+\.)+veezify\.com(?::\d+)?$',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
