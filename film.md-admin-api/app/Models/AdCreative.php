<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ad_campaign_id',
    'name',
    'media_url',
    'mime_type',
    'duration_seconds',
    'width',
    'height',
    'is_active',
    'meta',
])]
class AdCreative extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
