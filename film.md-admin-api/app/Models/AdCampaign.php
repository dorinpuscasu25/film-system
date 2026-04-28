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
    'frequency_cap_per_session',
    'frequency_cap_per_day',
    'target_countries',
    'target_groups',
    'target_content_ids',
    'target_excluded_content_ids',
    'mid_roll_offset_seconds',
    'bunny_webhook_secret',
    'impressions_count',
    'completes_count',
    'clicks_count',
    'skips_count',
    'total_spend_usd',
])]
class AdCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    public const PLACEMENT_PRE_ROLL = 'pre-roll';
    public const PLACEMENT_MID_ROLL = 'mid-roll';
    public const PLACEMENT_POST_ROLL = 'post-roll';

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    public function targetingRules(): HasMany
    {
        return $this->hasMany(AdTargetingRule::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AdEvent::class);
    }

    public function eventAggregates(): HasMany
    {
        return $this->hasMany(AdEventAggregate::class);
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
            'frequency_cap_per_session' => 'integer',
            'frequency_cap_per_day' => 'integer',
            'target_countries' => 'array',
            'target_groups' => 'array',
            'target_content_ids' => 'array',
            'target_excluded_content_ids' => 'array',
            'mid_roll_offset_seconds' => 'integer',
            'impressions_count' => 'integer',
            'completes_count' => 'integer',
            'clicks_count' => 'integer',
            'skips_count' => 'integer',
            'total_spend_usd' => 'float',
        ];
    }
}
