<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'content_id',
    'can_view',
    'can_view_stats',
    'meta',
])]
class UserContentAccess extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_view_stats' => 'boolean',
            'meta' => 'array',
        ];
    }
}
