<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\PlatformSetting;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generic key/value settings store. Used for GA4 Measurement ID, default
 * locale, marketing toggles, etc.
 */
class PlatformSettingsController extends ApiController
{
    public const KNOWN_KEYS = [
        'ga4_measurement_id',
        'ga4_api_secret',
        'default_locale',
        'available_locales',
        'newsletter_provider',
        'mpay_merchant_id',
        'social_links',
    ];

    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $settings = PlatformSetting::query()->whereIn('key', self::KNOWN_KEYS)->get()->keyBy('key');
        $out = [];
        foreach (self::KNOWN_KEYS as $key) {
            $out[$key] = $settings->get($key)?->value;
        }

        return response()->json(['settings' => $out]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        foreach ($data['settings'] as $key => $value) {
            if (! in_array($key, self::KNOWN_KEYS, true)) {
                continue;
            }
            PlatformSetting::setValue($key, $value);
        }

        $this->auditLog->record('platform_settings.updated', 'platform_settings', null, array_keys($data['settings']), $request->user(), $request);

        return $this->index();
    }
}
