<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'account_profile_id',
    'content_id',
    'episode_id',
    'content_format_id',
    'position_seconds',
    'duration_seconds',
    'watch_time_seconds',
    'last_watched_at',
    'is_completed',
    'meta',
])]
class WatchProgress extends Model
{
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'position_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'watch_time_seconds' => 'integer',
            'last_watched_at' => 'datetime',
            'is_completed' => 'boolean',
            'meta' => 'array',
        ];
    }
}
