<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_id',
    'content_format_id',
    'content_creator_id',
    'cost_settings_version_id',
    'month',
    'storage_cost_usd',
    'delivery_cost_usd',
    'drm_cost_usd',
    'revenue_usd',
    'profit_usd',
    'usd_to_mdl_rate',
    'is_locked',
    'meta',
])]
class VideoMonthlyCost extends Model
{
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(ContentFormat::class, 'content_format_id');
    }

    protected function casts(): array
    {
        return [
            'storage_cost_usd' => 'float',
            'delivery_cost_usd' => 'float',
            'drm_cost_usd' => 'float',
            'revenue_usd' => 'float',
            'profit_usd' => 'float',
            'usd_to_mdl_rate' => 'float',
            'is_locked' => 'boolean',
            'meta' => 'array',
        ];
    }
}
