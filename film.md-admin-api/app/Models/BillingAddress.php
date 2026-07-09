<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'full_name',
    'country_code',
    'administrative_area',
    'city',
    'postal_code',
    'address_line1',
    'address_line2',
    'is_default',
])]
class BillingAddress extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
