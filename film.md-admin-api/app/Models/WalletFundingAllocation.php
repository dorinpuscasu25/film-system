<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of one funding lot a debit (a purchase, a refund, a forfeit) consumed. */
#[Fillable(['debit_transaction_id', 'lot_id', 'amount'])]
class WalletFundingAllocation extends Model
{
    public function lot(): BelongsTo
    {
        return $this->belongsTo(WalletFundingLot::class, 'lot_id');
    }

    public function debitTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'debit_transaction_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
