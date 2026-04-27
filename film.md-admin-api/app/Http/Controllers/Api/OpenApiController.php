<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class OpenApiController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'filmoteca.md API',
                'version' => '1.0.0',
                'description' => 'Public, storefront and admin endpoints currently exposed by the platform.',
            ],
            'servers' => [
                ['url' => rtrim(config('app.url'), '/').'/api/v1'],
            ],
            'paths' => [
                '/public/home' => ['get' => ['summary' => 'Homepage sections', 'responses' => ['200' => ['description' => 'Homepage payload']]]],
                '/public/catalog' => ['get' => ['summary' => 'Catalog listing', 'responses' => ['200' => ['description' => 'Catalog payload']]]],
                '/public/content/{slug}' => ['get' => ['summary' => 'Content detail', 'responses' => ['200' => ['description' => 'Detailed content payload']]]],
                '/public/content/{slug}/premiere' => ['get' => ['summary' => 'Premiere/watch-party status', 'responses' => ['200' => ['description' => 'Premiere status payload']]]],
                '/storefront/account' => ['get' => ['summary' => 'Authenticated account summary', 'security' => [['bearerAuth' => []]]]],
                '/storefront/content/{identifier}/playback' => ['get' => ['summary' => 'Playback access resolution', 'security' => [['bearerAuth' => []]]]],
                '/storefront/content/{identifier}/playback/session' => ['post' => ['summary' => 'Create playback session', 'security' => [['bearerAuth' => []]]]],
                '/storefront/tracking/watch-progress' => ['post' => ['summary' => 'Playback tracking heartbeat/pause/stop/complete', 'security' => [['bearerAuth' => []]]]],
                '/storefront/continue-watching' => ['get' => ['summary' => 'Continue watching list', 'security' => [['bearerAuth' => []]]]],
                '/storefront/content/{identifier}/recommendations' => ['get' => ['summary' => 'Related content', 'security' => [['bearerAuth' => []]]]],
                '/ads/vast' => ['get' => ['summary' => 'Resolve VAST response']],
                '/ads/events' => ['post' => ['summary' => 'Track ad events']],
                '/webhooks/bunny/video' => ['post' => ['summary' => 'Receive Bunny video webhook']],
                '/webhooks/bunny/ads' => ['post' => ['summary' => 'Receive Bunny ad webhook']],
                '/admin/dashboard' => ['get' => ['summary' => 'Admin dashboard', 'security' => [['bearerAuth' => []]]]],
                '/admin/content' => ['get' => ['summary' => 'List content', 'security' => [['bearerAuth' => []]]], 'post' => ['summary' => 'Create content', 'security' => [['bearerAuth' => []]]]],
                '/admin/content/{content}' => ['get' => ['summary' => 'Show content', 'security' => [['bearerAuth' => []]]], 'patch' => ['summary' => 'Update content', 'security' => [['bearerAuth' => []]]], 'delete' => ['summary' => 'Delete content', 'security' => [['bearerAuth' => []]]]],
                '/admin/ad-campaigns' => ['get' => ['summary' => 'List ad campaigns', 'security' => [['bearerAuth' => []]]], 'post' => ['summary' => 'Create ad campaign', 'security' => [['bearerAuth' => []]]]],
                '/admin/exports' => ['get' => ['summary' => 'List export jobs', 'security' => [['bearerAuth' => []]]], 'post' => ['summary' => 'Create export job', 'security' => [['bearerAuth' => []]]]],
                '/admin/exports/{exportJob}/download' => ['get' => ['summary' => 'Download generated export', 'security' => [['bearerAuth' => []]]]],
                '/admin/audit-logs' => ['get' => ['summary' => 'Audit log', 'security' => [['bearerAuth' => []]]]],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Token',
                    ],
                ],
                'schemas' => [
                    'PremiereEvent' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'starts_at' => ['type' => 'string', 'format' => 'date-time'],
                            'ends_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        ],
                    ],
                    'AuditLogItem' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'timestamp' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            'user' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                            'target' => ['type' => 'string'],
                            'details' => ['type' => 'string'],
                        ],
                    ],
                    'PlaybackLock' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'premiere_event' => ['$ref' => '#/components/schemas/PremiereEvent'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
