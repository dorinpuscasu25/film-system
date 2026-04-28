<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Offer;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCouponController extends ApiController
{
    public function __construct(
        protected CouponService $coupons,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:64'],
            'offer_id' => ['required', 'integer'],
        ]);

        $offer = Offer::query()->findOrFail($data['offer_id']);
        $result = $this->coupons->preview($data['code'], $offer, $request->user());

        return response()->json([
            'coupon' => [
                'id' => $result['coupon']->id,
                'code' => $result['coupon']->code,
                'discount_type' => $result['coupon']->discount_type,
                'discount_value' => $result['coupon']->discount_value,
            ],
            'discount' => $result['discount'],
            'final_price' => $result['final_price'],
            'currency' => $result['currency'],
        ]);
    }
}
