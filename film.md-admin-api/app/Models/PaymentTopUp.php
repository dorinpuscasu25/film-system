<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'user_id',
    'wallet_id',
    'subscriber_id',
    'amount',
    'currency',
    'status',
    'provider_order_id',
    'provider_payment_url',
    'provider_status',
    'description',
    'raw_request',
    'raw_response',
    'raw_callback',
    'raw_details',
    'credited_at',
])]
class PaymentTopUp extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REDIRECT_CREATED = 'redirect_created';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
            self::STATUS_REFUNDED,
        ], true);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'raw_callback' => 'array',
            'raw_details' => 'array',
            'credited_at' => 'datetime',
        ];
    }
}
