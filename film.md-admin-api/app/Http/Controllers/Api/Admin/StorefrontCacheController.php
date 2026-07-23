<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\CloudflareCacheService;
use App\Services\StorefrontCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

class StorefrontCacheController extends ApiController
{
    public function destroy(
        StorefrontCacheService $cache,
        CloudflareCacheService $cloudflare,
    ): JsonResponse {
        $exitCode = Artisan::call('optimize:clear');

        if ($exitCode !== 0) {
            throw new RuntimeException('Application cache could not be cleared.');
        }

        if (app()->isProduction() && Artisan::call('optimize') !== 0) {
            throw new RuntimeException('Production cache could not be rebuilt.');
        }

        $cloudflareStatus = 'not_configured';

        try {
            $cloudflareStatus = $cloudflare->purgeEverything();
        } catch (Throwable $exception) {
            report($exception);
            $cloudflareStatus = 'failed';
        }

        return response()->json([
            'message' => $cloudflareStatus === 'failed'
                ? 'Application cache cleared, but Cloudflare purge failed.'
                : 'All configured caches were cleared.',
            'application_cache' => 'cleared',
            'cloudflare_cache' => $cloudflareStatus,
            'version' => $cache->clear(),
        ]);
    }
}
