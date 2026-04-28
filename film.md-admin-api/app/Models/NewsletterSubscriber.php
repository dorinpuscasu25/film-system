<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'email',
    'locale',
    'user_id',
    'is_active',
    'confirmed_at',
    'unsubscribed_at',
    'meta',
])]
class NewsletterSubscriber extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
