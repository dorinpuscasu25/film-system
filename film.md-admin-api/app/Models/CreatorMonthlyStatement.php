<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_creator_id',
    'month',
    'revenue_usd',
    'costs_usd',
    'payout_usd',
    'profit_usd',
    'is_locked',
    'meta',
])]
class CreatorMonthlyStatement extends Model
{
    public function creator(): BelongsTo
    {
        return $this->belongsTo(ContentCreator::class, 'content_creator_id');
    }

    protected function casts(): array
    {
        return [
            'revenue_usd' => 'float',
            'costs_usd' => 'float',
            'payout_usd' => 'float',
            'profit_usd' => 'float',
            'is_locked' => 'boolean',
            'meta' => 'array',
        ];
    }
}
