<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable price list ("Standard", "Premieră", …) that offers can be linked to. The prices
 * are copied onto every linked offer (offers.price_amount stays the single source the
 * storefront, search, coupons and reports read), and re-copied whenever the plan changes —
 * see PricePlanService.
 *
 * @property array<int, array{offer_type: string, quality: string, price_amount: float, rental_days: ?int}> $items
 */
#[Fillable([
    'name',
    'description',
    'currency',
    'items',
    'sort_order',
])]
class PricePlan extends Model
{
    /** Offer types a plan can price — free offers have no price to manage. */
    public static function pricedOfferTypes(): array
    {
        return [Offer::TYPE_RENTAL, Offer::TYPE_LIFETIME];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return array{offer_type: string, quality: string, price_amount: float, rental_days: ?int}|null
     */
    public function itemFor(string $offerType, string $quality): ?array
    {
        foreach ($this->items ?? [] as $item) {
            if (($item['offer_type'] ?? null) === $offerType && ($item['quality'] ?? null) === $quality) {
                return $item;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
