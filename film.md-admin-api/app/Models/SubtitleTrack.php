<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_id',
    'content_format_id',
    'locale',
    'label',
    'file_url',
    'is_default',
    'sort_order',
])]
class SubtitleTrack extends Model
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
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
