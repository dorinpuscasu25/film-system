<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'content_id',
    'name',
    'offer_type',
    'quality',
    'currency',
    'price_amount',
    'playback_url',
    'rental_days',
    'is_active',
    'starts_at',
    'ends_at',
    'sort_order',
])]
class Offer extends Model
{
    public const TYPE_FREE = 'free';
    public const TYPE_RENTAL = 'rental';
    public const TYPE_LIFETIME = 'lifetime';

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ContentEntitlement::class);
    }

    public static function availableTypes(): array
    {
        return [
            self::TYPE_FREE,
            self::TYPE_RENTAL,
            self::TYPE_LIFETIME,
        ];
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_FREE => 'Free',
            self::TYPE_RENTAL => 'Rental',
            self::TYPE_LIFETIME => 'Forever',
        ];
    }

    public function isCurrentlyAvailable(?Carbon $now = null): bool
    {
        $now ??= now();

        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->gt($now)) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->lt($now)) {
            return false;
        }

        return true;
    }

    protected function casts(): array
    {
        return [
            'price_amount' => 'float',
            'rental_days' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
