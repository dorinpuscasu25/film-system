<?php

namespace App\Http\Controllers\Api;

use App\Models\Content;
use App\Models\HomePageSection;
use App\Models\Offer;
use App\Services\ContentSearchService;
use App\Services\HomePageService;
use App\Services\IpGeoLocationService;
use App\Services\PlaybackAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PublicCatalogController extends ApiController
{
    public function __construct(
        protected ContentSearchService $contentSearch,
        protected HomePageService $homePageService,
        protected PlaybackAccessService $playbackAccess,
        protected IpGeoLocationService $geoLocation,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $countryCode = $this->geoLocation->resolveCountryCode($request);
        $legacyData = $this->legacyHomeData($locale, $countryCode);
        $resolvedSections = $this->homePageService->activeSections();
        $heroSection = $resolvedSections->firstWhere('section_type', HomePageSection::TYPE_HERO_SLIDER);
        $heroSlides = $heroSection
            ? $this->homePageService->resolveHeroSlides($heroSection)
                ->map(fn (array $slide) => $this->publicHeroSlideData($slide, $locale))
                ->values()
            : collect();
        $carouselSections = $resolvedSections
            ->where('section_type', HomePageSection::TYPE_CONTENT_CAROUSEL)
            ->map(function (HomePageSection $section) use ($locale, $countryCode): array {
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
                        ->filter(fn (Content $content) => $this->isCatalogVisible($content, $countryCode))
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
        $countryCode = $this->geoLocation->resolveCountryCode($request);
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
                ->filter(fn (Content $content) => $this->isCatalogVisible($content, $countryCode))
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
        $countryCode = $this->geoLocation->resolveCountryCode($request);
        $content = Content::query()
            ->published()
            ->with('taxonomies', 'offers', 'formats', 'rightsWindows', 'premiereEvents')
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

        if (! $this->isCatalogVisible($content, $countryCode)) {
            return response()->json([
                'message' => 'This title is not available in your territory.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json($this->publicContentDetailData($content, $locale));
    }

    public function sharePreview(Request $request, string $identifier): \Illuminate\Http\Response
    {
        $locale = $this->resolveLocale($request);
        $countryCode = $this->geoLocation->resolveCountryCode($request);
        $content = Content::query()
            ->published()
            ->with('taxonomies', 'offers', 'formats', 'rightsWindows', 'premiereEvents')
            ->where(function ($builder) use ($identifier): void {
                $builder->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $builder->orWhere('id', (int) $identifier);
                }
            })
            ->first();

        if ($content === null || ! $this->isCatalogVisible($content, $countryCode)) {
            return response('<!doctype html><html><head><title>filmoteca.md</title></head><body>Not found</body></html>', Response::HTTP_NOT_FOUND)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $frontendBaseUrl = rtrim((string) config('services.frontend.client_url', 'https://filmoteca.md'), '/');
        $data = $this->publicContentDetailData($content, $locale);
        $title = (string) (data_get($data, 'meta_title') ?: data_get($data, 'title') ?: $content->original_title);
        $shareTitle = Str::contains($title, 'filmoteca.md') ? $title : "{$title} | filmoteca.md";
        $shareUrl = $this->absolutePublicUrl((string) (data_get($data, 'canonical_url') ?: "/movie/{$content->slug}"), $frontendBaseUrl);
        $imageUrl = $this->absolutePublicUrl(
            (string) (data_get($data, 'hero_desktop_url') ?: data_get($data, 'backdrop_url') ?: data_get($data, 'poster_url') ?: ''),
            $frontendBaseUrl,
        );
        $description = $this->shareDescription($content, $data, $locale);

        $html = '<!doctype html><html lang="'.e($locale).'"><head>'
            .'<meta charset="UTF-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            .'<title>'.e($shareTitle).'</title>'
            .'<meta name="description" content="'.e($description).'">'
            .'<meta property="og:site_name" content="filmoteca.md">'
            .'<meta property="og:title" content="'.e($shareTitle).'">'
            .'<meta property="og:description" content="'.e($description).'">'
            .'<meta property="og:image" content="'.e($imageUrl).'">'
            .'<meta property="og:url" content="'.e($shareUrl).'">'
            .'<meta property="og:type" content="video.movie">'
            .'<meta name="twitter:card" content="summary_large_image">'
            .'<meta name="twitter:title" content="'.e($shareTitle).'">'
            .'<meta name="twitter:description" content="'.e($description).'">'
            .'<meta name="twitter:image" content="'.e($imageUrl).'">'
            .'<link rel="canonical" href="'.e($shareUrl).'">'
            .'<meta http-equiv="refresh" content="0;url='.e($shareUrl).'">'
            .'</head><body><a href="'.e($shareUrl).'">'.e($shareTitle).'</a></body></html>';

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=600');
    }

    public function premiere(Request $request, string $identifier): JsonResponse
    {
        $locale = $this->resolveLocale($request);
        $countryCode = $this->geoLocation->resolveCountryCode($request);
        $content = Content::query()
            ->published()
            ->with('taxonomies', 'offers', 'formats', 'rightsWindows', 'premiereEvents')
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

        if (! $this->isCatalogVisible($content, $countryCode)) {
            return response()->json([
                'message' => 'This title is not available in your territory.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nextPremiere = $this->playbackAccess->nextPublicPremiere($content);

        return response()->json([
            'content' => $this->publicContentCardData($content, $locale),
            'premiere_event' => $nextPremiere ? [
                'id' => $nextPremiere->id,
                'title' => $nextPremiere->title,
                'starts_at' => $nextPremiere->starts_at?->toIso8601String(),
                'ends_at' => $nextPremiere->ends_at?->toIso8601String(),
                'is_live' => $nextPremiere->starts_at !== null && $nextPremiere->starts_at->isPast(),
            ] : null,
            'watch_party' => [
                'is_locked' => $nextPremiere !== null,
                'entry_path' => '/watch/'.$content->slug,
            ],
        ]);
    }

    protected function resolveLocale(Request $request): string
    {
        $locale = (string) $request->query('locale', Content::supportedLocales()[0]);

        return in_array($locale, Content::supportedLocales(), true)
            ? $locale
            : Content::supportedLocales()[0];
    }

    protected function legacyHomeData(string $locale, ?string $countryCode = null): array
    {
        $baseQuery = Content::query()
            ->published()
            ->with('taxonomies', 'offers', 'formats', 'rightsWindows', 'premiereEvents')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('release_year');

        $featured = (clone $baseQuery)->where('is_featured', true)->limit(16)->get()
            ->filter(fn (Content $content) => $this->isCatalogVisible($content, $countryCode))
            ->take(8)
            ->values();
        $hero = $featured->first()
            ?? (clone $baseQuery)->limit(24)->get()->first(fn (Content $content) => $this->isCatalogVisible($content, $countryCode));
        $allPublished = (clone $baseQuery)->limit(40)->get()
            ->filter(fn (Content $content) => $this->isCatalogVisible($content, $countryCode))
            ->take(24)
            ->values();

        return [
            'hero' => $hero ? $this->publicContentCardData($hero, $locale) : null,
            'featured' => $featured->map(fn (Content $content) => $this->publicContentCardData($content, $locale))->values(),
            'free_to_watch' => (clone $baseQuery)
                ->where(function ($builder): void {
                    $builder
                        ->where('is_free', true)
                        ->orWhereHas('offers', fn ($offerQuery) => $offerQuery->where('offer_type', Offer::TYPE_FREE));
                })
                ->limit(16)
                ->get()
                ->filter(fn (Content $content) => $this->isCatalogVisible($content, $countryCode))
                ->take(8)
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'latest' => (clone $baseQuery)
                ->orderByDesc('release_year')
                ->limit(16)
                ->get()
                ->filter(fn (Content $content) => $this->isCatalogVisible($content, $countryCode))
                ->take(8)
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'movies' => $allPublished
                ->where('type', '!=', Content::TYPE_SERIES)
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
            'series' => $allPublished
                ->where('type', Content::TYPE_SERIES)
                ->map(fn (Content $content) => $this->publicContentCardData($content, $locale))
                ->values(),
        ];
    }

    protected function isCatalogVisible(Content $content, ?string $countryCode): bool
    {
        if (! $this->playbackAccess->isContentCurrentlyAvailable($content)) {
            return false;
        }

        $format = $this->playbackAccess->resolveAvailableFormat($content, $countryCode);

        return $format !== null;
    }

    protected function absolutePublicUrl(string $url, string $frontendBaseUrl): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return rtrim($frontendBaseUrl, '/').'/'.ltrim($url, '/');
    }

    protected function shareDescription(Content $content, array $data, string $locale): string
    {
        $description = (string) (data_get($data, 'meta_description')
            ?: data_get($data, 'short_description')
            ?: data_get($data, 'description')
            ?: '');
        $description = trim(preg_replace('/\s+/', ' ', strip_tags($description)) ?: '');
        $details = collect([
            data_get($data, 'release_year'),
            Content::typeLabel($content->type, $locale),
            collect(data_get($data, 'genres', []))->take(2)->implode(', '),
        ])->filter()->implode(' · ');
        $suffix = $details !== '' ? " {$details}." : '';
        $text = "{$description}{$suffix} Vezi {$content->original_title} online pe filmoteca.md.";

        return Str::limit(trim($text), 220);
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
