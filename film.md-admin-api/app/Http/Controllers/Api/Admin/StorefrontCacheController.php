<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\StorefrontCacheService;
use Illuminate\Http\JsonResponse;

class StorefrontCacheController extends ApiController
{
    public function destroy(StorefrontCacheService $cache): JsonResponse
    {
        return response()->json([
            'message' => 'Storefront cache cleared.',
            'version' => $cache->clear(),
        ]);
    }
}
