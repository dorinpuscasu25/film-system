<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ad_campaign_id',
    'content_id',
    'date',
    'event_type',
    'country_code',
    'count',
])]
class AdEventAggregate extends Model
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
            'date' => 'date',
            'count' => 'integer',
        ];
    }
}
