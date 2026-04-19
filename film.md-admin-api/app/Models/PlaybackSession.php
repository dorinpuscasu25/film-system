<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'content_id',
    'content_format_id',
    'offer_id',
    'account_profile_id',
    'session_token',
    'country_code',
    'device_type',
    'status',
    'started_at',
    'ended_at',
    'watch_time_seconds',
    'max_position_seconds',
    'counted_as_view',
    'meta',
])]
class PlaybackSession extends Model
{
    public const STATUS_STARTED = 'started';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_COMPLETED = 'completed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(ContentFormat::class, 'content_format_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(WatchProgress::class, 'content_id', 'content_id');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'watch_time_seconds' => 'integer',
            'max_position_seconds' => 'integer',
            'counted_as_view' => 'boolean',
            'meta' => 'array',
        ];
    }
}
