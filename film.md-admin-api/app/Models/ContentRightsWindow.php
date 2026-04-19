<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_id',
    'content_format_id',
    'country_code',
    'is_allowed',
    'starts_at',
    'ends_at',
    'meta',
])]
class ContentRightsWindow extends Model
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
            'is_allowed' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
