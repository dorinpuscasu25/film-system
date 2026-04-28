<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'description',
    'discount_type',
    'discount_value',
    'currency',
    'max_redemptions',
    'redemptions_count',
    'per_user_limit',
    'starts_at',
    'ends_at',
    'is_active',
    'applicable_content_ids',
    'applicable_offer_ids',
    'meta',
])]
class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_FREE_ACCESS = 'free_access';

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $now = now();
        if ($this->starts_at !== null && $this->starts_at->gt($now)) {
            return false;
        }
        if ($this->ends_at !== null && $this->ends_at->lt($now)) {
            return false;
        }
        if ($this->max_redemptions !== null && $this->redemptions_count >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    protected function casts(): array
    {
        return [
            'discount_value' => 'float',
            'max_redemptions' => 'integer',
            'redemptions_count' => 'integer',
            'per_user_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'applicable_content_ids' => 'array',
            'applicable_offer_ids' => 'array',
            'meta' => 'array',
        ];
    }
}
