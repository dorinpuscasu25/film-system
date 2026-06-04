<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

#[Fillable([
    'uuid',
    'payment_top_up_id',
    'user_id',
    'wallet_id',
    'wallet_transaction_id',
    'requested_by_admin_id',
    'provider_order_id',
    'provider_checkout_id',
    'provider_rrn',
    'amount',
    'currency',
    'reason',
    'status',
    'provider_status',
    'raw_request',
    'raw_response',
    'processed_at',
])]
class PaymentRefund extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function topUp(): BelongsTo
    {
        return $this->belongsTo(PaymentTopUp::class, 'payment_top_up_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_admin_id');
    }

    public function walletTransactionReference(): MorphOne
    {
        return $this->morphOne(WalletTransaction::class, 'reference');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'raw_request' => 'array',
            'raw_response' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
