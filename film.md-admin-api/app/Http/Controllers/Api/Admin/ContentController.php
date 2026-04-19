<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Admin\StoreContentRequest;
use App\Http\Requests\Admin\UpdateContentRequest;
use App\Models\Content;
use App\Models\ContentFormat;
use App\Models\Offer;
use App\Models\Taxonomy;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use App\Services\ContentSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class ContentController extends ApiController
{
    public function __construct(
        protected ContentSearchService $contentSearch,
        protected ContentScopeService $contentScope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $user = request()->user();
        $locale = $user?->preferred_locale ?? Content::supportedLocales()[0];
        $items = $this->contentScope->scopeContentQuery($user, Content::query())
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
        $this->contentScope->assertCanAccessContent(request()->user(), $content);
        $locale = request()->user()?->preferred_locale ?? Content::supportedLocales()[0];

        return response()->json([
            'content' => $this->contentData($content->load('taxonomies', 'offers', 'formats', 'rightsWindows', 'subtitleTracks', 'creators', 'premiereEvents'), $locale),
            'options' => $this->contentOptionsData(),
        ]);
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $content = Content::query()->create($request->normalizedPayload());
        $content->syncTaxonomyIds($request->taxonomyIds());
        $this->syncContentExtensions($content, collect($request->normalizedPayload()));
        Content::recalculateTaxonomyCounts();
        $freshContent = $content->fresh()->load('taxonomies', 'offers', 'formats', 'rightsWindows', 'subtitleTracks', 'creators', 'premiereEvents');
        $this->contentSearch->syncContent($freshContent);
        $this->auditLog->record(
            'content.created',
            'content',
            $content->id,
            ['title' => $content->original_title, 'slug' => $content->slug],
            $request->user(),
            $request,
        );

        return response()->json([
            'content' => $this->contentData(
                $freshContent,
                $request->user()?->preferred_locale ?? Content::supportedLocales()[0],
            ),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateContentRequest $request, Content $content): JsonResponse
    {
        $this->contentScope->assertCanAccessContent($request->user(), $content);
        $content->fill($request->normalizedPayload())->save();
        $content->syncTaxonomyIds($request->taxonomyIds());
        $this->syncContentExtensions($content, collect($request->normalizedPayload()));
        Content::recalculateTaxonomyCounts();
        $freshContent = $content->fresh()->load('taxonomies', 'offers', 'formats', 'rightsWindows', 'subtitleTracks', 'creators', 'premiereEvents');
        $this->contentSearch->syncContent($freshContent);
        $this->auditLog->record(
            'content.updated',
            'content',
            $content->id,
            ['title' => $content->original_title, 'slug' => $content->slug, 'status' => $content->status],
            $request->user(),
            $request,
        );

        return response()->json([
            'content' => $this->contentData(
                $freshContent,
                $request->user()?->preferred_locale ?? Content::supportedLocales()[0],
            ),
        ]);
    }

    public function destroy(Content $content): JsonResponse
    {
        $this->contentScope->assertCanAccessContent(request()->user(), $content);
        $contentId = $content->getKey();
        $title = $content->original_title;
        $slug = $content->slug;
        $content->delete();
        Content::recalculateTaxonomyCounts();
        $this->contentSearch->syncContent($contentId);
        $this->auditLog->record(
            'content.deleted',
            'content',
            $contentId,
            ['title' => $title, 'slug' => $slug],
            request()->user(),
            request(),
        );

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
            'format_types' => [
                ['value' => 'main', 'label' => 'Main'],
                ['value' => 'trailer', 'label' => 'Trailer'],
            ],
        ];
    }

    protected function syncContentExtensions(Content $content, Collection $payload): void
    {
        $formatsPayload = collect($payload->get('content_formats', []))->values();

        $content->formats()->delete();
        $createdFormats = $formatsPayload
            ->map(fn (array $item) => $content->formats()->create($item))
            ->values();

        $qualityToFormatId = $createdFormats
            ->mapWithKeys(fn (ContentFormat $format) => [$format->quality => $format->id]);

        $content->rightsWindows()->delete();
        collect($payload->get('rights_windows', []))
            ->each(function (array $item) use ($content, $qualityToFormatId): void {
                $content->rightsWindows()->create([
                    'content_format_id' => $qualityToFormatId->get(data_get($item, 'content_format_quality')),
                    'country_code' => data_get($item, 'country_code'),
                    'is_allowed' => (bool) data_get($item, 'is_allowed', true),
                    'starts_at' => data_get($item, 'starts_at'),
                    'ends_at' => data_get($item, 'ends_at'),
                    'meta' => data_get($item, 'meta', []),
                ]);
            });

        $content->subtitleTracks()->delete();
        collect($payload->get('subtitle_tracks', []))
            ->each(function (array $item) use ($content, $qualityToFormatId): void {
                $content->subtitleTracks()->create([
                    'content_format_id' => $qualityToFormatId->get(data_get($item, 'content_format_quality')),
                    'locale' => data_get($item, 'locale'),
                    'label' => data_get($item, 'label'),
                    'file_url' => data_get($item, 'file_url'),
                    'is_default' => (bool) data_get($item, 'is_default', false),
                    'sort_order' => (int) data_get($item, 'sort_order', 0),
                ]);
            });

        $content->premiereEvents()->delete();
        collect($payload->get('premiere_events', []))
            ->each(function (array $item) use ($content): void {
                $content->premiereEvents()->create([
                    'title' => data_get($item, 'title'),
                    'starts_at' => data_get($item, 'starts_at'),
                    'ends_at' => data_get($item, 'ends_at'),
                    'is_active' => (bool) data_get($item, 'is_active', true),
                    'is_public' => (bool) data_get($item, 'is_public', true),
                    'meta' => data_get($item, 'meta', []),
                ]);
            });

        $creatorIds = collect($payload->get('creator_ids', []))
            ->mapWithKeys(fn (int $creatorId) => [$creatorId => ['role' => 'owner', 'is_primary' => false]])
            ->all();
        $content->creators()->sync($creatorIds);
    }
}
