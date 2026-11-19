<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'user_id',
    'wallet_id',
    'provider',
    'payment_method',
    'provider_reference',
    'billing_address_id',
    'subscriber_id',
    'amount',
    'currency',
    'access_location',
    'status',
    'provider_order_id',
    'provider_checkout_id',
    'provider_rrn',
    'provider_payment_url',
    'qr_header_uuid',
    'qr_extension_uuid',
    'qr_as_text',
    'qr_expires_at',
    'last_checked_at',
    'provider_status',
    'description',
    'billing_address',
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

    public const PROVIDER_PAY_FILMOTECA = 'pay_filmoteca';

    public const PROVIDER_MIA = 'mia';

    public const PROVIDER_CYBERSOURCE = 'cybersource';

    public const METHOD_CARD = 'card';

    public const METHOD_MIA = 'mia';

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

    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(BillingAddress::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
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
            'billing_address' => 'array',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'raw_callback' => 'array',
            'raw_details' => 'array',
            'credited_at' => 'datetime',
            'qr_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
