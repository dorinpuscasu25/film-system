<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['domestic_country_code', 'domestic_vat_rate', 'effective_from', 'effective_until', 'is_active', 'created_by', 'meta'])]
class ReportingSettingsVersion extends Model
{
    protected function casts(): array
    {
        return [
            'domestic_vat_rate' => 'float',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
