<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Coupon;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CouponController extends ApiController
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where(function ($qq) use ($term) {
                $qq->where('code', 'ilike', "%{$term}%")
                    ->orWhere('name', 'ilike', "%{$term}%");
            }))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json([
            'items' => $coupons->getCollection()->map(fn (Coupon $c) => $this->present($c))->values(),
            'pagination' => [
                'page' => $coupons->currentPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $payload['code'] = strtoupper(trim($payload['code']));
        $coupon = Coupon::query()->create($payload);

        $this->auditLog->record('coupon.created', 'coupon', $coupon->id, ['code' => $coupon->code], $request->user(), $request);

        return response()->json(['coupon' => $this->present($coupon)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $payload = $this->validatePayload($request, $coupon);
        if (isset($payload['code'])) {
            $payload['code'] = strtoupper(trim($payload['code']));
        }
        $coupon->fill($payload)->save();

        $this->auditLog->record('coupon.updated', 'coupon', $coupon->id, ['code' => $coupon->code], $request->user(), $request);

        return response()->json(['coupon' => $this->present($coupon->fresh())]);
    }

    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        $coupon->delete();
        $this->auditLog->record('coupon.deleted', 'coupon', $coupon->id, ['code' => $coupon->code], $request->user(), $request);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    private function present(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'description' => $c->description,
            'discount_type' => $c->discount_type,
            'discount_value' => $c->discount_value,
            'currency' => $c->currency,
            'max_redemptions' => $c->max_redemptions,
            'redemptions_count' => $c->redemptions_count,
            'per_user_limit' => $c->per_user_limit,
            'starts_at' => $c->starts_at?->toIso8601String(),
            'ends_at' => $c->ends_at?->toIso8601String(),
            'is_active' => $c->is_active,
            'is_currently_valid' => $c->isCurrentlyValid(),
            'applicable_content_ids' => $c->applicable_content_ids,
            'applicable_offer_ids' => $c->applicable_offer_ids,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function validatePayload(Request $request, ?Coupon $coupon = null): array
    {
        $codeRule = ['required', 'string', 'min:3', 'max:64'];
        if ($coupon !== null) {
            $codeRule = ['sometimes', 'string', 'min:3', 'max:64'];
        }

        return $request->validate([
            'code' => $codeRule,
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', 'string', 'in:percent,fixed,free_access'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'applicable_content_ids' => ['nullable', 'array'],
            'applicable_offer_ids' => ['nullable', 'array'],
        ]);
    }
}
