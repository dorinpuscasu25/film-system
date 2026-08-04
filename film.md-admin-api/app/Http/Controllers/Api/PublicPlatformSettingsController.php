<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\CmsPage;
use App\Models\PlatformSetting;
use App\Models\Taxonomy;
use App\Services\StorefrontCacheService;
use Illuminate\Http\JsonResponse;

/**
 * Public-readable subset of platform settings (storefront uses GA4 ID, locales).
 */
class PublicPlatformSettingsController extends ApiController
{
    public function __construct(
        protected StorefrontCacheService $storefrontCache,
    ) {}

    public function show(): JsonResponse
    {
        $locale = (string) request()->query('locale', PlatformSetting::getValue('default_locale', Taxonomy::LOCALE_RO));
        $payload = $this->storefrontCache->remember('public-settings', [
            'locale' => $locale,
        ], function (): array {
            $termsPage = $this->termsPageData();

            return [
                'ga4_measurement_id' => PlatformSetting::getValue('ga4_measurement_id'),
                'default_locale' => PlatformSetting::getValue('default_locale', 'ro'),
                'available_locales' => PlatformSetting::getValue('available_locales', ['ro', 'ru', 'en']),
                'social_links' => PlatformSetting::getValue('social_links', []),
                'seo' => PlatformSetting::getValue('seo', []),
                'terms_page' => $termsPage,
                'terms_page_url' => $termsPage['url'] ?? null,
                'contact' => $this->contactData(),
            ];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'private, max-age=60, stale-while-revalidate=300')
            ->header('X-Storefront-Cache-Version', (string) $this->storefrontCache->version());
    }

    private function contactData(): ?array
    {
        $contact = PlatformSetting::getValue('contact', []);
        if (! is_array($contact)) {
            return null;
        }

        $locale = (string) request()->query('locale', PlatformSetting::getValue('default_locale', Taxonomy::LOCALE_RO));
        $locale = in_array($locale, Taxonomy::supportedLocales(), true) ? $locale : Taxonomy::LOCALE_RO;
        $localized = static function (mixed $field) use ($locale): ?string {
            if (is_string($field)) {
                return filled($field) ? trim($field) : null;
            }

            if (! is_array($field)) {
                return null;
            }

            $value = $field[$locale] ?? $field[Taxonomy::LOCALE_RO] ?? collect($field)->first(fn (mixed $item): bool => filled($item));

            return filled($value) ? trim((string) $value) : null;
        };

        $payload = [
            'operator_name' => filled($contact['operator_name'] ?? null) ? trim((string) $contact['operator_name']) : null,
            'email' => filled($contact['email'] ?? null) ? trim((string) $contact['email']) : null,
            'phone' => filled($contact['phone'] ?? null) ? trim((string) $contact['phone']) : null,
            'address' => $localized($contact['address'] ?? null),
            'working_hours' => $localized($contact['working_hours'] ?? null),
            'description' => $localized($contact['description'] ?? null),
        ];

        return collect($payload)->contains(fn (mixed $value): bool => filled($value)) ? $payload : null;
    }

    private function termsPageData(): ?array
    {
        $pageId = PlatformSetting::getValue('terms_page_id');

        if (! $pageId) {
            return null;
        }

        $page = CmsPage::query()
            ->published()
            ->whereKey((int) $pageId)
            ->first();

        if (! $page) {
            return null;
        }

        $locale = request()->query('locale', PlatformSetting::getValue('default_locale', Taxonomy::LOCALE_RO));
        $locale = in_array($locale, Taxonomy::supportedLocales(), true) ? $locale : Taxonomy::LOCALE_RO;
        $fallback = Taxonomy::LOCALE_RO;
        $slug = $page->getTranslation('slug', $locale, false)
            ?: $page->getTranslation('slug', $fallback, false)
            ?: collect($page->getTranslations('slug'))->filter()->first();
        $title = $page->getTranslation('title', $locale, false)
            ?: $page->getTranslation('title', $fallback, false)
            ?: collect($page->getTranslations('title'))->filter()->first();

        if (! $slug) {
            return null;
        }

        return [
            'id' => $page->id,
            'title' => $title,
            'slug' => $slug,
            'url' => '/page/'.$slug,
        ];
    }
}
