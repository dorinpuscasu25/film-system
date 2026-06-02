<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\CmsPage;
use App\Models\PlatformSetting;
use App\Models\Taxonomy;
use Illuminate\Http\JsonResponse;

/**
 * Public-readable subset of platform settings (storefront uses GA4 ID, locales).
 */
class PublicPlatformSettingsController extends ApiController
{
    public function show(): JsonResponse
    {
        $termsPage = $this->termsPageData();

        return response()->json([
            'ga4_measurement_id' => PlatformSetting::getValue('ga4_measurement_id'),
            'default_locale' => PlatformSetting::getValue('default_locale', 'ro'),
            'available_locales' => PlatformSetting::getValue('available_locales', ['ro', 'ru', 'en']),
            'social_links' => PlatformSetting::getValue('social_links', []),
            'seo' => PlatformSetting::getValue('seo', []),
            'terms_page' => $termsPage,
            'terms_page_url' => $termsPage['url'] ?? null,
        ]);
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
