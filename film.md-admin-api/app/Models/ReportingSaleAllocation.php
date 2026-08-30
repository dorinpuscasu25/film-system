<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reporting_sale_id', 'content_creator_id', 'contract_version_id', 'fiscal_profile_id', 'holder_name', 'share_percent', 'base_share_amount', 'vat_amount', 'gross_share_amount', 'withholding_rate', 'withholding_amount', 'net_payable_amount', 'person_type', 'is_vat_registered', 'contract_snapshot', 'fiscal_snapshot'])]
class ReportingSaleAllocation extends Model
{
    public function sale(): BelongsTo { return $this->belongsTo(ReportingSale::class, 'reporting_sale_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(ContentCreator::class, 'content_creator_id'); }

    protected function casts(): array
    {
        return [
            'share_percent' => 'float', 'base_share_amount' => 'float', 'vat_amount' => 'float', 'gross_share_amount' => 'float',
            'withholding_rate' => 'float', 'withholding_amount' => 'float', 'net_payable_amount' => 'float',
            'is_vat_registered' => 'boolean', 'contract_snapshot' => 'array', 'fiscal_snapshot' => 'array',
        ];
    }
}
