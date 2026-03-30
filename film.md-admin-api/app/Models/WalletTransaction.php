<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'wallet_id',
    'user_id',
    'type',
    'amount',
    'balance_after',
    'currency',
    'reference_type',
    'reference_id',
    'description',
    'meta',
    'processed_at',
])]
class WalletTransaction extends Model
{
    public const TYPE_WELCOME_BONUS = 'welcome_bonus';
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_TOP_UP = 'top_up';

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
