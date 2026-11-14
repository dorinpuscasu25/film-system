<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\Offer;
use App\Models\PricePlan;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use App\Services\PricePlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PricePlanController extends ApiController
{
    public function __construct(
        protected PricePlanService $pricePlans,
        protected ContentScopeService $contentScope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $plans = PricePlan::query()
            ->withCount('offers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $contentCounts = Offer::query()
            ->whereNotNull('price_plan_id')
            ->groupBy('price_plan_id')
            ->selectRaw('price_plan_id, count(distinct content_id) as contents_count')
            ->pluck('contents_count', 'price_plan_id');
        $plans->each(fn (PricePlan $plan) => $plan->setAttribute('contents_count', (int) ($contentCounts[$plan->id] ?? 0)));

        return response()->json([
            'items' => $plans->map(fn (PricePlan $plan) => $this->present($plan))->values(),
            'options' => [
                'offer_types' => collect(PricePlan::pricedOfferTypes())
                    ->map(fn (string $type) => ['value' => $type, 'label' => Offer::typeLabels()[$type]])
                    ->values(),
                'qualities' => Content::availableQualities(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanManagePlans($request);
        $attributes = $this->validatePlan($request);
        $plan = PricePlan::query()->create($attributes);

        $this->auditLog->record('price_plan.created', 'price_plan', $plan->id, ['name' => $plan->name, 'items' => $plan->items], $request->user(), $request);

        return response()->json(['plan' => $this->present($plan->loadCount('offers'))], Response::HTTP_CREATED);
    }

    public function update(Request $request, PricePlan $pricePlan): JsonResponse
    {
        $this->assertCanManagePlans($request);
        $attributes = $this->validatePlan($request);
        $before = $pricePlan->items;
        $result = $this->pricePlans->save($pricePlan, $attributes);

        $this->auditLog->record('price_plan.updated', 'price_plan', $pricePlan->id, [
            'name' => $pricePlan->name,
            'items_before' => $before,
            'items_after' => $pricePlan->items,
        ] + $result, $request->user(), $request);

        return response()->json([
            'plan' => $this->present($pricePlan->fresh()->loadCount('offers')),
            'result' => $result,
        ]);
    }

    public function preview(Request $request, PricePlan $pricePlan): JsonResponse
    {
        $attributes = $this->validatePlan($request);

        return response()->json(
            $this->pricePlans->previewChanges($pricePlan, $attributes['items'], $attributes['currency'])
        );
    }

    public function apply(Request $request, PricePlan $pricePlan): JsonResponse
    {
        $data = $request->validate([
            'content_ids' => ['required', 'array', 'min:1', 'max:500'],
            'content_ids.*' => ['integer', Rule::exists('contents', 'id')],
        ]);
        $contents = Content::query()->whereIn('id', array_unique($data['content_ids']))->get();
        $contents->each(fn (Content $content) => $this->contentScope->assertCanAccessContent($request->user(), $content));

        $report = $this->pricePlans->applyToContents($pricePlan, $contents);

        $this->auditLog->record('price_plan.applied', 'price_plan', $pricePlan->id, [
            'name' => $pricePlan->name,
            'content_ids' => $contents->pluck('id')->all(),
        ], $request->user(), $request);

        return response()->json([
            'plan' => $this->present($pricePlan->fresh()->loadCount('offers')),
            'results' => $report,
        ]);
    }

    public function destroy(Request $request, PricePlan $pricePlan): JsonResponse
    {
        $this->assertCanManagePlans($request);
        $linkedOffers = $pricePlan->offers()->count();
        // offers.price_plan_id is nullOnDelete: linked offers stay, with the price they have now.
        $pricePlan->delete();

        $this->auditLog->record('price_plan.deleted', 'price_plan', $pricePlan->id, [
            'name' => $pricePlan->name,
            'detached_offers' => $linkedOffers,
        ], $request->user(), $request);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    /** A plan reprices every title linked to it, so producers scoped to their own titles can't edit plans. */
    protected function assertCanManagePlans(Request $request): void
    {
        if ($this->contentScope->isScoped($request->user())) {
            throw new HttpException(403, 'Planurile de preț afectează tot catalogul.');
        }
    }

    /**
     * @return array{name: string, description: ?string, currency: string, sort_order: int, items: array<int, array<string, mixed>>}
     */
    protected function validatePlan(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.offer_type' => ['required', Rule::in(PricePlan::pricedOfferTypes())],
            'items.*.quality' => ['required', Rule::in(Content::availableQualities())],
            'items.*.price_amount' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'items.*.rental_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $items = [];
        $seen = [];
        $errors = [];

        foreach ($data['items'] as $index => $item) {
            $key = $item['offer_type'].'|'.$item['quality'];
            if (isset($seen[$key])) {
                $errors["items.{$index}.quality"] = 'Planul are deja un preț pentru acest tip și această calitate.';
            }
            $seen[$key] = true;

            $isRental = $item['offer_type'] === Offer::TYPE_RENTAL;
            if ($isRental && empty($item['rental_days'])) {
                $errors["items.{$index}.rental_days"] = 'Închirierea are nevoie de un număr de zile.';
            }

            $items[] = [
                'offer_type' => $item['offer_type'],
                'quality' => $item['quality'],
                'price_amount' => round((float) $item['price_amount'], 2),
                'rental_days' => $isRental ? (int) ($item['rental_days'] ?? 0) : null,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'currency' => strtoupper($data['currency'] ?? Content::DEFAULT_CURRENCY),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'items' => $items,
        ];
    }

    protected function present(PricePlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'sort_order' => $plan->sort_order,
            'items' => $plan->items ?? [],
            'offers_count' => (int) ($plan->offers_count ?? 0),
            'contents_count' => (int) ($plan->contents_count ?? $plan->offers()->distinct()->count('content_id')),
            'created_at' => $plan->created_at?->toIso8601String(),
            'updated_at' => $plan->updated_at?->toIso8601String(),
        ];
    }
}
