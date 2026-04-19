<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ad_campaign_id',
    'country_code',
    'allowed_group',
    'content_id',
    'is_include_rule',
])]
class AdTargetingRule extends Model
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    protected function casts(): array
    {
        return [
            'is_include_rule' => 'boolean',
        ];
    }
}
