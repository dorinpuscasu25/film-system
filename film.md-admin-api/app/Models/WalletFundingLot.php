<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One credit that entered a wallet (a MAIB top-up, an Apple pack, a bonus), with what it is
 * worth in real money. Debits consume lots oldest first; see WalletFundingLedger.
 */
#[Fillable([
    'wallet_id', 'user_id', 'source', 'credit_transaction_id', 'source_type', 'source_id', 'amount', 'remaining_amount',
    'currency', 'paid_amount', 'paid_currency', 'gross_value', 'commission_value', 'fx_cost_value', 'net_value',
    'valuation_status', 'valuation_meta', 'funded_at',
])]
class WalletFundingLot extends Model
{
    public const SOURCE_OWN = 'own';           // paid by card through MAIB (pay.filmoteca.md)

    public const SOURCE_APPLE = 'apple';       // App Store credit pack

    public const SOURCE_PLATFORM = 'platform'; // bonus / promotional credit: no revenue

    /** The order the wallet spends buckets in (WalletService::allocateDebitFunding). */
    public const SPEND_ORDER = [self::SOURCE_PLATFORM, self::SOURCE_APPLE, self::SOURCE_OWN];

    public const VALUATION_FINAL = 'final';

    public const VALUATION_ESTIMATED = 'estimated';

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'credit_transaction_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(WalletFundingAllocation::class, 'lot_id');
    }

    /** Value of $credits of this lot, pro rata. @return array{gross: float, commission: float, fx_cost: float, net: float} */
    public function valueOf(float $credits): array
    {
        $ratio = (float) $this->amount > 0 ? $credits / (float) $this->amount : 0.0;

        return [
            'gross' => round((float) $this->gross_value * $ratio, 4),
            'commission' => round((float) $this->commission_value * $ratio, 4),
            'fx_cost' => round((float) $this->fx_cost_value * $ratio, 4),
            'net' => round((float) $this->net_value * $ratio, 4),
        ];
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float', 'remaining_amount' => 'float', 'paid_amount' => 'float', 'gross_value' => 'float',
            'commission_value' => 'float', 'fx_cost_value' => 'float', 'net_value' => 'float',
            'valuation_meta' => 'array', 'funded_at' => 'datetime',
        ];
    }
}
