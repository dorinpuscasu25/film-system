<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'content_id',
    'host_user_id',
    'title',
    'room_code',
    'scheduled_start_at',
    'actual_start_at',
    'ended_at',
    'status',
    'is_public',
    'chat_enabled',
    'max_participants',
    'meta',
])]
class WatchParty extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(WatchPartyParticipant::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(WatchPartyChatMessage::class);
    }

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_public' => 'boolean',
            'chat_enabled' => 'boolean',
            'max_participants' => 'integer',
            'meta' => 'array',
        ];
    }
}
