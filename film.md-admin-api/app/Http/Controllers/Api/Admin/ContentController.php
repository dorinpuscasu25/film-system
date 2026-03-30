<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Admin\StoreContentRequest;
use App\Http\Requests\Admin\UpdateContentRequest;
use App\Models\Content;
use App\Models\Offer;
use App\Models\Taxonomy;
use App\Services\ContentSearchService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ContentController extends ApiController
{
    public function __construct(
        protected ContentSearchService $contentSearch,
    ) {}

    public function index(): JsonResponse
    {
        $locale = request()->user()?->preferred_locale ?? Content::supportedLocales()[0];
        $items = Content::query()
            ->with('taxonomies', 'offers')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'items' => $items->map(fn (Content $content) => $this->contentData($content, $locale)),
            'filters' => [
                'types' => collect(Content::typeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'statuses' => collect(Content::statusLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'countries' => collect(Content::countryOptions())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
            ],
        ]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'options' => $this->contentOptionsData(),
        ]);
    }

    public function show(Content $content): JsonResponse
    {
        $locale = request()->user()?->preferred_locale ?? Content::supportedLocales()[0];

        return response()->json([
            'content' => $this->contentData($content->load('taxonomies', 'offers'), $locale),
            'options' => $this->contentOptionsData(),
        ]);
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $content = Content::query()->create($request->normalizedPayload());
        $content->syncTaxonomyIds($request->taxonomyIds());
        Content::recalculateTaxonomyCounts();
        $this->contentSearch->syncContent($content->fresh()->load('taxonomies', 'offers'));

        return response()->json([
            'content' => $this->contentData(
                $content->fresh()->load('taxonomies', 'offers'),
                $request->user()?->preferred_locale ?? Content::supportedLocales()[0],
            ),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateContentRequest $request, Content $content): JsonResponse
    {
        $content->fill($request->normalizedPayload())->save();
        $content->syncTaxonomyIds($request->taxonomyIds());
        Content::recalculateTaxonomyCounts();
        $this->contentSearch->syncContent($content->fresh()->load('taxonomies', 'offers'));

        return response()->json([
            'content' => $this->contentData(
                $content->fresh()->load('taxonomies', 'offers'),
                $request->user()?->preferred_locale ?? Content::supportedLocales()[0],
            ),
        ]);
    }

    public function destroy(Content $content): JsonResponse
    {
        $contentId = $content->getKey();
        $content->delete();
        Content::recalculateTaxonomyCounts();
        $this->contentSearch->syncContent($contentId);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    protected function contentOptionsData(): array
    {
        $locale = request()->user()?->preferred_locale ?? Content::supportedLocales()[0];
        $taxonomies = Taxonomy::query()
            ->where('active', true)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get()
            ->groupBy('type')
            ->map(
                fn ($items) => $items->map(
                    fn (Taxonomy $taxonomy) => [
                        'id' => $taxonomy->id,
                        'type' => $taxonomy->type,
                        'slug' => $taxonomy->slug,
                        'name' => $taxonomy->getTranslations('name'),
                        'color' => $taxonomy->color,
                    ],
                )->values(),
            );

        return [
            'locales' => collect(Content::supportedLocales())
                ->map(fn (string $locale) => ['value' => $locale, 'label' => strtoupper($locale)])
                ->values(),
            'types' => collect(Content::typeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'statuses' => collect(Content::statusLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'countries' => collect(Content::countryOptions())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'age_ratings' => collect(Content::availableAgeRatings())->values(),
            'quality_options' => collect(Content::availableQualities())->values(),
            'offer_types' => collect(Offer::typeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'video_types' => collect(Content::videoTypeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'cast_credit_types' => collect(Content::castCreditTypeTranslations())
                ->map(fn (array $labels, string $value) => [
                    'value' => $value,
                    'label' => $labels[$locale] ?? $labels['ro'] ?? $value,
                ])
                ->values(),
            'crew_credit_types' => collect(Content::crewCreditTypeTranslations())
                ->map(fn (array $labels, string $value) => [
                    'value' => $value,
                    'label' => $labels[$locale] ?? $labels['ro'] ?? $value,
                ])
                ->values(),
            'taxonomies' => [
                Taxonomy::TYPE_GENRE => $taxonomies->get(Taxonomy::TYPE_GENRE, collect())->values(),
                Taxonomy::TYPE_COLLECTION => $taxonomies->get(Taxonomy::TYPE_COLLECTION, collect())->values(),
                Taxonomy::TYPE_TAG => $taxonomies->get(Taxonomy::TYPE_TAG, collect())->values(),
                Taxonomy::TYPE_BADGE => $taxonomies->get(Taxonomy::TYPE_BADGE, collect())->values(),
            ],
        ];
    }
}
