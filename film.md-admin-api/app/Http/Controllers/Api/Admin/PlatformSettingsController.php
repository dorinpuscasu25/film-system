<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\PlatformSetting;
use App\Services\AuditLogService;
use App\Services\RegistrationCreditService;
use App\Services\StorefrontCacheService;
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
        'seo',
        'terms_page_id',
        'contact',
        RegistrationCreditService::SETTINGS_KEY,
    ];

    public function __construct(
        protected AuditLogService $auditLog,
        protected RegistrationCreditService $registrationCredit,
        protected StorefrontCacheService $storefrontCache,
    ) {}

    public function index(): JsonResponse
    {
        $settings = PlatformSetting::query()->whereIn('key', self::KNOWN_KEYS)->get()->keyBy('key');
        $out = [];
        foreach (self::KNOWN_KEYS as $key) {
            $out[$key] = $key === RegistrationCreditService::SETTINGS_KEY
                ? $this->registrationCredit->normalizeSettings($settings->get($key)?->value ?? [])
                : $settings->get($key)?->value;
        }

        return response()->json(['settings' => $out]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.contact' => ['sometimes', 'array'],
            'settings.contact.operator_name' => ['nullable', 'string', 'max:255'],
            'settings.contact.email' => ['nullable', 'email', 'max:255'],
            'settings.contact.phone' => ['nullable', 'string', 'max:100'],
            'settings.contact.address' => ['nullable', 'array'],
            'settings.contact.address.*' => ['nullable', 'string', 'max:1000'],
            'settings.contact.working_hours' => ['nullable', 'array'],
            'settings.contact.working_hours.*' => ['nullable', 'string', 'max:500'],
            'settings.contact.description' => ['nullable', 'array'],
            'settings.contact.description.*' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data['settings'] as $key => $value) {
            if (! in_array($key, self::KNOWN_KEYS, true)) {
                continue;
            }
            if ($key === RegistrationCreditService::SETTINGS_KEY) {
                $value = $this->registrationCredit->normalizeSettings(is_array($value) ? $value : []);
            }
            if ($key === 'terms_page_id') {
                $value = filled($value) ? (int) $value : null;
            }
            if ($key === 'contact') {
                $value = $this->normalizeContactSettings(is_array($value) ? $value : []);
            }
            PlatformSetting::setValue($key, $value);
        }

        $this->auditLog->record('platform_settings.updated', 'platform_settings', null, array_keys($data['settings']), $request->user(), $request);
        $this->storefrontCache->clear();

        return $this->index();
    }

    private function normalizeContactSettings(array $value): array
    {
        $localized = static function (mixed $field): array {
            $field = is_array($field) ? $field : [];

            return collect(['ro', 'ru', 'en'])
                ->mapWithKeys(fn (string $locale): array => [
                    $locale => trim((string) ($field[$locale] ?? '')),
                ])
                ->all();
        };

        return [
            'operator_name' => trim((string) ($value['operator_name'] ?? '')),
            'email' => trim((string) ($value['email'] ?? '')),
            'phone' => trim((string) ($value['phone'] ?? '')),
            'address' => $localized($value['address'] ?? []),
            'working_hours' => $localized($value['working_hours'] ?? []),
            'description' => $localized($value['description'] ?? []),
        ];
    }
}
