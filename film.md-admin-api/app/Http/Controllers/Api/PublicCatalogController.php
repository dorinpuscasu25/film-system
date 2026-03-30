<?php

namespace App\Http\Controllers\Api;

use App\Models\Content;
use App\Models\Offer;
use App\Services\ContentSearchService;
use App\Services\HomePageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class PublicCatalogController extends ApiController
{
    public function __construct(
        protected ContentSearchService $contentSearch,
        protected HomePageService $homePageService,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $legacyData = $this->legacyHomeData($locale);
        $resolvedSections = $this->homePageService->activeSections();
        $heroSection = $resolvedSections->firstWhere('section_type', \App\Models\HomePageSection::TYPE_HERO_SLIDER);
        $heroSlides = $heroSection
            ? $this->homePageService->resolveHeroSlides($heroSection)
                ->map(fn (array $slide) => $this->publicHeroSlideData($slide, $locale))
                ->values()
            : collect();
        $carouselSections = $resolvedSections
            ->where('section_type', \App\Models\HomePageSection::TYPE_CONTENT_CAROUSEL)
            ->map(function (\App\Models\HomePageSection $section) use ($locale): array {
                return [
                    'id' => (string) $section->id,
                    'name' => $section->name,
                    'section_type' => $section->section_type,
                    'source_mode' => $section->source_mode,
                    'sort_order' => (int) $section->sort_order,
                    'title' => $section->getTranslation('title', $locale, false)
                        ?? $section->getTranslation('title', 'ro', false)
                        ?? $section->name,
                    'subtitle' => $section->getTranslation('subtitle', $locale, false)
                        ?? $section->getTranslation('subtitle', 'ro', false),
                    'items' => $this->homePageService->resolveCarouselItems($section)
                        ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $section): bool => count($section['items']) > 0)
            ->values();
        $hero = $heroSlides->first()['content'] ?? $legacyData['hero'];

        return response()->json([
            'locale' => $locale,
            'hero_slides' => $heroSlides->values(),
            'sections' => $carouselSections,
            ...$legacyData,
            'hero' => $hero,
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $page = max((int) $request->integer('page', 1), 1);
        $pageSize = min(max((int) $request->integer('page_size', 24), 1), 100);
        $result = $this->contentSearch->searchCatalog($locale, [
            'query' => $request->query('query'),
            'type' => $request->query('type'),
            'genre' => $request->query('genre'),
            'access' => $request->query('access'),
            'year' => $request->query('year'),
            'country' => $request->query('country'),
            'min_rating' => $request->query('min_rating'),
            'page' => $page,
            'page_size' => $pageSize,
        ]);

        return response()->json([
            'items' => collect($result['items'] ?? [])
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'page' => $result['page'] ?? $page,
            'page_size' => $result['page_size'] ?? $pageSize,
            'total' => $result['total'] ?? 0,
            'filters' => $result['filters'] ?? [],
            'search_engine' => $result['engine'] ?? 'database',
        ]);
    }

    public function show(Request $request, string $identifier): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $content = Content::query()
            ->published()
            ->with('taxonomies', 'offers')
            ->where(function ($builder) use ($identifier): void {
                $builder->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $builder->orWhere('id', (int) $identifier);
                }
            })
            ->first();

        if ($content === null) {
            return response()->json([
                'message' => 'The requested content was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($this->publicContentDetailData($content, $locale));
    }

    protected function resolveLocale(Request $request): string
    {
        $locale = (string) $request->query('locale', Content::supportedLocales()[0]);

        return in_array($locale, Content::supportedLocales(), true)
            ? $locale
            : Content::supportedLocales()[0];
    }

    protected function legacyHomeData(string $locale): array
    {
        $baseQuery = Content::query()
            ->published()
            ->with('taxonomies', 'offers')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('release_year');

        $featured = (clone $baseQuery)->where('is_featured', true)->limit(8)->get();
        $hero = $featured->first() ?? (clone $baseQuery)->first();
        $allPublished = (clone $baseQuery)->limit(24)->get();

        return [
            'hero' => $hero ? $this->publicContentCardData($hero, $locale) : null,
            'featured' => $featured->map(fn (Content $content) => $this->publicContentCardData($content, $locale))->values(),
            'free_to_watch' => (clone $baseQuery)
                ->where(function ($builder): void {
                    $builder
                        ->where('is_free', true)
                        ->orWhereHas('offers', fn ($offerQuery) => $offerQuery->where('offer_type', Offer::TYPE_FREE));
                })
                ->limit(8)
                ->get()
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'latest' => (clone $baseQuery)
                ->orderByDesc('release_year')
                ->limit(8)
                ->get()
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'movies' => $allPublished
                ->where('type', Content::TYPE_MOVIE)
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'series' => $allPublished
                ->where('type', Content::TYPE_SERIES)
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
        ];
    }

    protected function publicHeroSlideData(array $slide, string $locale): array
    {
        /** @var Content $content */
        $content = $slide['content'];

        return [
            'id' => (string) data_get($slide, 'id'),
            'content_id' => (string) $content->id,
            'desktop_image_url' => data_get($slide, 'desktop_image_url') ?: ($content->hero_desktop_url ?: $content->backdrop_url),
            'mobile_image_url' => data_get($slide, 'mobile_image_url') ?: ($content->hero_mobile_url ?: $content->poster_url),
            'eyebrow' => data_get($slide, "eyebrow.{$locale}")
                ?: data_get($slide, 'eyebrow.ro'),
            'title' => data_get($slide, "title.{$locale}")
                ?: data_get($slide, 'title.ro')
                ?: ($content->getTranslation('title', $locale, false)
                    ?? $content->getTranslation('title', $content->default_locale ?: 'ro', false)
                    ?? $content->original_title),
            'description' => data_get($slide, "description.{$locale}")
                ?: data_get($slide, 'description.ro')
                ?: ($content->getTranslation('short_description', $locale, false)
                    ?? $content->getTranslation('short_description', $content->default_locale ?: 'ro', false)),
            'primary_cta_label' => data_get($slide, "primary_cta_label.{$locale}")
                ?: data_get($slide, 'primary_cta_label.ro')
                ?: 'More info',
            'secondary_cta_label' => data_get($slide, "secondary_cta_label.{$locale}")
                ?: data_get($slide, 'secondary_cta_label.ro'),
            'content' => $this->publicContentCardData($content, $locale),
        ];
    }
}
