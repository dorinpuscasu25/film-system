<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The part of a film sale paid by one funding lot, valued in real money (contract 6.5). */
#[Fillable(['reporting_sale_id', 'lot_id', 'source', 'credits', 'gross_value', 'commission_value', 'fx_cost_value', 'net_value', 'valuation_status'])]
class ReportingSaleFunding extends Model
{
    public function sale(): BelongsTo
    {
        return $this->belongsTo(ReportingSale::class, 'reporting_sale_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(WalletFundingLot::class, 'lot_id');
    }

    protected function casts(): array
    {
        return [
            'credits' => 'float', 'gross_value' => 'float', 'commission_value' => 'float',
            'fx_cost_value' => 'float', 'net_value' => 'float',
        ];
    }
}
