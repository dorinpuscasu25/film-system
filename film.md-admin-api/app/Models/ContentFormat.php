<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'content_id',
    'quality',
    'format_type',
    'bunny_library_id',
    'bunny_video_id',
    'stream_url',
    'token_path',
    'drm_policy',
    'is_active',
    'is_default',
    'sort_order',
    'meta',
])]
class ContentFormat extends Model
{
    public const TYPE_MAIN = 'main';
    public const TYPE_TRAILER = 'trailer';

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function rightsWindows(): HasMany
    {
        return $this->hasMany(ContentRightsWindow::class);
    }

    public function subtitleTracks(): HasMany
    {
        return $this->hasMany(SubtitleTrack::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }
}
