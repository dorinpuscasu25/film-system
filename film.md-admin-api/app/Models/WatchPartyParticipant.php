<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'watch_party_id',
    'user_id',
    'account_profile_id',
    'display_name',
    'joined_at',
    'left_at',
    'is_host',
    'meta',
])]
class WatchPartyParticipant extends Model
{
    public function watchParty(): BelongsTo
    {
        return $this->belongsTo(WatchParty::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'is_host' => 'boolean',
            'meta' => 'array',
        ];
    }
}
