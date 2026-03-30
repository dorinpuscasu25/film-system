<?php

return [
    'driver' => env('SEARCH_DRIVER', 'database'),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
    ],

    'locales' => [
        'ro' => ['ron'],
        'ru' => ['rus'],
        'en' => ['eng'],
    ],

    'indexes' => [
        'content' => [
            'uid' => env('MEILISEARCH_CONTENT_INDEX', 'storefront_content'),
        ],
    ],
];
