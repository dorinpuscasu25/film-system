<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Admin\StoreTaxonomyRequest;
use App\Http\Requests\Admin\UpdateTaxonomyRequest;
use App\Models\Taxonomy;
use App\Services\ContentSearchService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class TaxonomyController extends ApiController
{
    public function __construct(
        protected ContentSearchService $contentSearch,
    ) {}

    public function index(): JsonResponse
    {
        $locale = request()->user()?->preferred_locale ?? Taxonomy::LOCALE_RO;
        $groupedTaxonomies = Taxonomy::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get()
            ->groupBy('type')
            ->map(fn ($items) => $items->map(fn (Taxonomy $taxonomy) => $this->taxonomyData($taxonomy, $locale))->values());

        $taxonomies = collect(Taxonomy::availableTypes())
            ->mapWithKeys(fn (string $type) => [$type => $groupedTaxonomies->get($type, collect())->values()]);

        return response()->json([
            'types' => collect(Taxonomy::typeLabels())
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'locales' => [
                ['value' => Taxonomy::LOCALE_RO, 'label' => 'RO'],
                ['value' => Taxonomy::LOCALE_RU, 'label' => 'RU'],
                ['value' => Taxonomy::LOCALE_EN, 'label' => 'EN'],
            ],
            'taxonomies' => $taxonomies,
        ]);
    }

    public function store(StoreTaxonomyRequest $request): JsonResponse
    {
        $taxonomy = Taxonomy::query()->create($request->normalizedPayload());

        return response()->json([
            'taxonomy' => $this->taxonomyData($taxonomy, $request->user()?->preferred_locale ?? Taxonomy::LOCALE_RO),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateTaxonomyRequest $request, Taxonomy $taxonomy): JsonResponse
    {
        $contentIds = $taxonomy->contents()->pluck('contents.id')->all();
        $taxonomy->fill($request->normalizedPayload())->save();
        $this->contentSearch->syncContentIds($contentIds);

        return response()->json([
            'taxonomy' => $this->taxonomyData($taxonomy->fresh(), $request->user()?->preferred_locale ?? Taxonomy::LOCALE_RO),
        ]);
    }

    public function destroy(Taxonomy $taxonomy): JsonResponse
    {
        $contentIds = $taxonomy->contents()->pluck('contents.id')->all();
        $taxonomy->delete();
        $this->contentSearch->syncContentIds($contentIds);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
