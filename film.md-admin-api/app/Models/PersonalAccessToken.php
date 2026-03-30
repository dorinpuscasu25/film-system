<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'token_hash', 'last_used_at', 'expires_at'])]
class PersonalAccessToken extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function issue(User $user, string $name = 'auth_token'): array
    {
        $plainTextToken = Str::random(80);
        $ttlMinutes = max((int) config('app.api_token_ttl_minutes', 10080), 1);

        $token = self::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return [$token, $plainTextToken];
    }

    public static function findValidToken(string $plainTextToken): ?self
    {
        $token = self::query()
            ->with('user.roles.permissions')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($token === null || $token->isExpired()) {
            return null;
        }

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
