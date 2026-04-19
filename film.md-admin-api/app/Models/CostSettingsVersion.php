<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'storage_cost_per_gb_day',
    'delivery_cost_per_gb',
    'drm_cost_per_license',
    'usd_to_mdl_rate',
    'effective_from',
    'effective_until',
    'is_active',
])]
class CostSettingsVersion extends Model
{
    protected function casts(): array
    {
        return [
            'storage_cost_per_gb_day' => 'float',
            'delivery_cost_per_gb' => 'float',
            'drm_cost_per_license' => 'float',
            'usd_to_mdl_rate' => 'float',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
