<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Admin\UpdateHomePageSectionsRequest;
use App\Models\Content;
use App\Models\HomePageSection;
use App\Models\Taxonomy;
use App\Services\HomePageService;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class HomeCurationController extends ApiController
{
    public function __construct(
        protected HomePageService $homePageService,
        protected MediaUploadService $mediaUpload,
    ) {}

    public function index(): JsonResponse
    {
        $locale = request()->user()?->preferred_locale ?? Content::supportedLocales()[0];
        $contents = $this->contentOptionCollection($locale);
        $contentsById = $contents->keyBy('id');

        return response()->json([
            'sections' => $this->homePageService->listSections()
                ->map(fn (HomePageSection $section) => $this->sectionData($section, $locale, $contentsById))
                ->values(),
            'options' => [
                'locales' => collect(Content::supportedLocales())
                    ->map(fn (string $value) => ['value' => $value, 'label' => strtoupper($value)])
                    ->values(),
                'section_types' => collect(HomePageSection::typeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'source_modes' => collect(HomePageSection::sourceModeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'sort_modes' => collect(HomePageSection::sortModeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'access_modes' => collect(HomePageSection::accessModeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'matching_strategies' => collect(HomePageSection::matchStrategyLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'content_types' => collect(Content::typeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'taxonomies' => $this->taxonomyOptionGroups($locale),
                'contents' => $contents->values(),
            ],
        ]);
    }

    public function update(UpdateHomePageSectionsRequest $request): JsonResponse
    {
        $locale = $request->user()?->preferred_locale ?? Content::supportedLocales()[0];
        $contents = $this->contentOptionCollection($locale);
        $contentsById = $contents->keyBy('id');

        $normalized = $this->uploadInlineImages($request->normalizedSections());
        $sections = $this->homePageService->replaceSections($normalized);

        return response()->json([
            'sections' => $sections
                ->map(fn (HomePageSection $section) => $this->sectionData($section, $locale, $contentsById))
                ->values(),
        ]);
    }

    protected function contentOptionCollection(string $locale): Collection
    {
        return Content::query()
            ->with('taxonomies', 'offers')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Content $content) use ($locale): array {
                $card = $this->publicContentCardData($content, $locale);

                return [
                    'id' => $content->id,
                    'slug' => $content->slug,
                    'type' => $content->type,
                    'status' => $content->status,
                    'title' => $card['title'],
                    'original_title' => $content->original_title,
                    'release_year' => $content->release_year,
                    'poster_url' => $content->poster_url,
                    'backdrop_url' => $content->backdrop_url,
                    'hero_desktop_url' => $content->hero_desktop_url,
                    'hero_mobile_url' => $content->hero_mobile_url,
                    'lowest_price' => $card['lowest_price'],
                    'currency' => $card['currency'],
                    'is_featured' => (bool) $content->is_featured,
                    'is_trending' => (bool) $content->is_trending,
                    'genres' => collect($card['genres'] ?? [])->values()->all(),
                    'collections' => collect($card['collections'] ?? [])->values()->all(),
                    'tags' => collect($card['tags'] ?? [])->values()->all(),
                    'badges' => collect($card['badges'] ?? [])->values()->all(),
                ];
            });
    }

    protected function taxonomyOptionGroups(string $locale): array
    {
        return Taxonomy::query()
            ->where('active', true)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get()
            ->groupBy('type')
            ->map(fn (Collection $items) => $items->map(fn (Taxonomy $taxonomy) => [
                'id' => $taxonomy->id,
                'type' => $taxonomy->type,
                'slug' => $taxonomy->slug,
                'color' => $taxonomy->color,
                'name' => $taxonomy->getTranslations('name'),
                'localized_name' => $taxonomy->getTranslation('name', $locale, false)
                    ?? $taxonomy->getTranslation('name', 'ro', false)
                    ?? $taxonomy->slug,
            ])->values()->all())
            ->all();
    }

    /**
     * Walk normalized sections and convert any base64 data-URIs in hero slide
     * image fields into Cloudflare R2 URLs. URLs already on R2 (or any http)
     * are passed through unchanged.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    protected function uploadInlineImages(array $sections): array
    {
        return array_map(function (array $section): array {
            if (! isset($section['hero_slides']) || ! is_array($section['hero_slides'])) {
                return $section;
            }

            $section['hero_slides'] = array_map(function (array $slide): array {
                foreach (['desktop_image_url', 'mobile_image_url'] as $field) {
                    $value = $slide[$field] ?? null;
                    if (is_string($value) && $value !== '') {
                        $slide[$field] = $this->mediaUpload->resolveImageUrl($value, 'home-sections');
                    }
                }

                return $slide;
            }, $section['hero_slides']);

            return $section;
        }, $sections);
    }

    protected function sectionData(HomePageSection $section, string $locale, Collection $contentsById): array
    {
        $defaultLocale = Content::supportedLocales()[0];
        $heroSlides = collect($section->hero_slides ?? [])
            ->sortBy('sort_order')
            ->map(fn (array $slide) => $this->heroSlideData($slide, $locale, $defaultLocale, $contentsById))
            ->values();
        $selectedContent = collect($section->content_ids ?? [])
            ->map(fn ($contentId) => $contentsById->get((int) $contentId))
            ->filter()
            ->values();

        return [
            'id' => $section->id,
            'name' => $section->name,
            'section_type' => $section->section_type,
            'active' => (bool) $section->active,
            'sort_order' => (int) $section->sort_order,
            'title' => $section->getTranslations('title'),
            'subtitle' => $section->getTranslations('subtitle'),
            'localized_title' => $section->getTranslation('title', $locale, false)
                ?? $section->getTranslation('title', $defaultLocale, false),
            'localized_subtitle' => $section->getTranslation('subtitle', $locale, false)
                ?? $section->getTranslation('subtitle', $defaultLocale, false),
            'source_mode' => $section->source_mode,
            'limit' => $section->limit,
            'content_ids' => collect($section->content_ids ?? [])->map(fn ($value) => (int) $value)->values()->all(),
            'selected_content' => $selectedContent->all(),
            'rule_filters' => $section->rule_filters ?? [
                'taxonomy_ids' => [],
                'content_types' => [],
                'access' => HomePageSection::ACCESS_ALL,
                'sort_mode' => HomePageSection::SORT_RELEASE_YEAR_DESC,
                'matching_strategy' => HomePageSection::MATCH_ANY,
                'featured_only' => false,
                'trending_only' => false,
            ],
            'hero_slides' => $heroSlides->all(),
            'meta' => $section->meta ?? [],
        ];
    }

    protected function heroSlideData(
        array $slide,
        string $locale,
        string $defaultLocale,
        Collection $contentsById,
    ): array {
        $translations = fn (mixed $value): array => $this->translatableValue($value);
        $localized = fn (mixed $value): ?string => $this->localizedValue($value, $locale, $defaultLocale);
        $content = $contentsById->get((int) data_get($slide, 'content_id'));

        return [
            'id' => (string) data_get($slide, 'id'),
            'content_id' => (int) data_get($slide, 'content_id'),
            'active' => (bool) data_get($slide, 'active', true),
            'sort_order' => (int) data_get($slide, 'sort_order', 0),
            'desktop_image_url' => data_get($slide, 'desktop_image_url'),
            'mobile_image_url' => data_get($slide, 'mobile_image_url'),
            'eyebrow' => $translations(data_get($slide, 'eyebrow')),
            'title' => $translations(data_get($slide, 'title')),
            'description' => $translations(data_get($slide, 'description')),
            'primary_cta_label' => $translations(data_get($slide, 'primary_cta_label')),
            'secondary_cta_label' => $translations(data_get($slide, 'secondary_cta_label')),
            'localized_eyebrow' => $localized(data_get($slide, 'eyebrow')),
            'localized_title' => $localized(data_get($slide, 'title')),
            'localized_description' => $localized(data_get($slide, 'description')),
            'localized_primary_cta_label' => $localized(data_get($slide, 'primary_cta_label')),
            'localized_secondary_cta_label' => $localized(data_get($slide, 'secondary_cta_label')),
            'content' => $content,
        ];
    }
}
