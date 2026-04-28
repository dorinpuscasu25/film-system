<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

/**
 * Public-readable subset of platform settings (storefront uses GA4 ID, locales).
 */
class PublicPlatformSettingsController extends ApiController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'ga4_measurement_id' => PlatformSetting::getValue('ga4_measurement_id'),
            'default_locale' => PlatformSetting::getValue('default_locale', 'ro'),
            'available_locales' => PlatformSetting::getValue('available_locales', ['ro', 'ru', 'en']),
            'social_links' => PlatformSetting::getValue('social_links', []),
        ]);
    }
}
