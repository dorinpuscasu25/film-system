<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'email', 'purpose', 'code_hash', 'expires_at', 'consumed_at', 'meta'])]
class EmailVerificationCode extends Model
{
    public const PURPOSE_REGISTRATION = 'registration';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->expires_at->lte($now);
    }

    public function scopeActive($query, ?CarbonInterface $now = null)
    {
        $now ??= now();

        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
