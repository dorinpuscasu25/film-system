<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'company_name',
    'vast_tag_url',
    'click_through_url',
    'placement',
    'status',
    'bid_amount',
    'skip_offset_seconds',
    'starts_at',
    'ends_at',
    'is_active',
    'meta',
])]
class AdCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    public function targetingRules(): HasMany
    {
        return $this->hasMany(AdTargetingRule::class);
    }

    protected function casts(): array
    {
        return [
            'bid_amount' => 'float',
            'skip_offset_seconds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
