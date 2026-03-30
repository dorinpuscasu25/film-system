<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'content_id',
    'offer_id',
    'access_type',
    'quality',
    'status',
    'currency',
    'price_amount',
    'granted_at',
    'starts_at',
    'expires_at',
    'revoked_at',
    'meta',
])]
class ContentEntitlement extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function isActive(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if ($this->status !== self::STATUS_ACTIVE || $this->revoked_at !== null) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->gt($now)) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->gt($now);
    }

    public function scopeActive($query, ?CarbonInterface $now = null)
    {
        $now ??= now();

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where(function ($builder) use ($now): void {
                $builder->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($builder) use ($now): void {
                $builder->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
    }

    protected function casts(): array
    {
        return [
            'price_amount' => 'float',
            'meta' => 'array',
            'granted_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
