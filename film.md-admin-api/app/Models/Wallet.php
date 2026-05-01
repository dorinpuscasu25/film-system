<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'currency', 'balance_amount', 'meta'])]
class Wallet extends Model
{
    public const DEFAULT_CURRENCY = 'MDL';
    public const DEFAULT_WELCOME_CREDIT = 100;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('processed_at')->latest('id');
    }

    protected function casts(): array
    {
        return [
            'balance_amount' => 'float',
            'meta' => 'array',
        ];
    }
}
