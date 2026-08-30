<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['uuid', 'content_entitlement_id', 'wallet_transaction_id', 'buyer_user_id', 'content_id', 'offer_id', 'settings_version_id', 'purchased_at', 'status', 'calculation_status', 'calculation_version', 'country_code', 'market', 'sales_channel', 'payment_method', 'payment_processor', 'currency', 'content_title', 'offer_name', 'quality', 'rental_days', 'gross_amount', 'vat_rate', 'vat_amount', 'net_ex_vat_amount', 'holders_gross_amount', 'holders_vat_amount', 'withholding_amount', 'holders_net_amount', 'platform_share_amount', 'platform_vat_amount', 'refund_amount', 'refunded_at', 'source_snapshot', 'calculation_snapshot'])]
class ReportingSale extends Model
{
    public function allocations(): HasMany { return $this->hasMany(ReportingSaleAllocation::class); }
    public function content(): BelongsTo { return $this->belongsTo(Content::class); }
    public function offer(): BelongsTo { return $this->belongsTo(Offer::class); }
    public function entitlement(): BelongsTo { return $this->belongsTo(ContentEntitlement::class, 'content_entitlement_id'); }

    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime', 'refunded_at' => 'datetime', 'source_snapshot' => 'array', 'calculation_snapshot' => 'array',
            'gross_amount' => 'float', 'vat_rate' => 'float', 'vat_amount' => 'float', 'net_ex_vat_amount' => 'float',
            'holders_gross_amount' => 'float', 'holders_vat_amount' => 'float', 'withholding_amount' => 'float',
            'holders_net_amount' => 'float', 'platform_share_amount' => 'float', 'platform_vat_amount' => 'float', 'refund_amount' => 'float',
        ];
    }
}
