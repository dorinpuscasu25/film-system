<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Validates and redeems promotional coupons.
 *
 * Discount types:
 *  - percent      → percent off offer.price_amount
 *  - fixed        → fixed currency amount off
 *  - free_access  → effective price becomes 0
 */
class CouponService
{
    /**
     * @return array{
     *   coupon: Coupon,
     *   discount: float,
     *   final_price: float,
     *   currency: string,
     * }
     */
    public function preview(string $code, Offer $offer, ?User $user = null): array
    {
        $coupon = $this->findUsable($code);
        $this->assertApplicable($coupon, $offer);
        $this->assertUserLimit($coupon, $user);

        return $this->computeDiscount($coupon, $offer);
    }

    public function redeem(string $code, Offer $offer, ?User $user, ?int $contentId = null): CouponRedemption
    {
        return DB::transaction(function () use ($code, $offer, $user, $contentId): CouponRedemption {
            $coupon = $this->findUsable($code, lockForUpdate: true);
            $this->assertApplicable($coupon, $offer);
            $this->assertUserLimit($coupon, $user);
            $result = $this->computeDiscount($coupon, $offer);

            $redemption = CouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'user_id' => $user?->id,
                'offer_id' => $offer->id,
                'content_id' => $contentId ?? $offer->content_id,
                'discount_applied' => $result['discount'],
                'currency' => $result['currency'],
            ]);

            $coupon->increment('redemptions_count');

            return $redemption;
        });
    }

    /**
     * @return array{coupon: Coupon, discount: float, final_price: float, currency: string}
     */
    private function computeDiscount(Coupon $coupon, Offer $offer): array
    {
        $price = (float) $offer->price_amount;
        $discount = 0.0;

        switch ($coupon->discount_type) {
            case Coupon::TYPE_PERCENT:
                $discount = round($price * ((float) $coupon->discount_value) / 100, 2);
                break;
            case Coupon::TYPE_FIXED:
                $discount = min($price, (float) $coupon->discount_value);
                break;
            case Coupon::TYPE_FREE_ACCESS:
                $discount = $price;
                break;
        }

        $final = max(0.0, $price - $discount);

        return [
            'coupon' => $coupon,
            'discount' => $discount,
            'final_price' => $final,
            'currency' => (string) $offer->currency,
        ];
    }

    private function findUsable(string $code, bool $lockForUpdate = false): Coupon
    {
        $code = strtoupper(trim($code));
        $query = Coupon::query()->where('code', $code);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $coupon = $query->first();
        if ($coupon === null) {
            throw new HttpException(404, 'Cuponul nu a fost găsit.');
        }
        if (! $coupon->isCurrentlyValid()) {
            throw new HttpException(422, 'Cuponul nu mai este valabil.');
        }

        return $coupon;
    }

    private function assertApplicable(Coupon $coupon, Offer $offer): void
    {
        $applicableContents = $coupon->applicable_content_ids ?? [];
        if (! empty($applicableContents) && ! in_array($offer->content_id, array_map('intval', $applicableContents), true)) {
            throw new HttpException(422, 'Cuponul nu se aplică pe acest film.');
        }

        $applicableOffers = $coupon->applicable_offer_ids ?? [];
        if (! empty($applicableOffers) && ! in_array($offer->id, array_map('intval', $applicableOffers), true)) {
            throw new HttpException(422, 'Cuponul nu se aplică pe această ofertă.');
        }
    }

    private function assertUserLimit(Coupon $coupon, ?User $user): void
    {
        if ($user === null || $coupon->per_user_limit <= 0) {
            return;
        }

        $count = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->count();

        if ($count >= $coupon->per_user_limit) {
            throw new HttpException(422, 'Ai folosit deja acest cupon de numărul maxim de ori permis.');
        }
    }
}
