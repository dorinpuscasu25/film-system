<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_creator_id', 'person_type', 'tax_residency', 'is_vat_registered', 'vat_rate', 'withholding_enabled', 'withholding_rate', 'tax_identifier', 'iban', 'payment_currency', 'effective_from', 'effective_until', 'status', 'created_by', 'meta'])]
class CreatorFiscalProfile extends Model
{
    public function creator(): BelongsTo { return $this->belongsTo(ContentCreator::class, 'content_creator_id'); }

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean', 'vat_rate' => 'float', 'withholding_enabled' => 'boolean',
            'withholding_rate' => 'float', 'effective_from' => 'date', 'effective_until' => 'date', 'meta' => 'array',
        ];
    }
}
