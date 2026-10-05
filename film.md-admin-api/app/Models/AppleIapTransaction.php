<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id', 'original_transaction_id', 'user_id', 'wallet_id', 'product_id', 'app_account_token',
    'credits_granted', 'apple_price', 'apple_currency', 'environment', 'status', 'signed_transaction',
    'decoded_payload', 'credited_at', 'refunded_at', 'refund_reason',
])]
class AppleIapTransaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CREDITED = 'credited';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_REJECTED = 'rejected';

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function wallet(): BelongsTo { return $this->belongsTo(Wallet::class); }

    protected function casts(): array
    {
        return [
            'credits_granted' => 'float', 'apple_price' => 'integer', 'decoded_payload' => 'array',
            'credited_at' => 'datetime', 'refunded_at' => 'datetime',
        ];
    }
}
