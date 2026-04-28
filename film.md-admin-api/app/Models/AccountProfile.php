<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
    'name',
    'avatar_label',
    'avatar_color',
    'is_kids',
    'is_default',
    'sort_order',
    'pin_hash',
    'max_age_rating',
    'preferred_locale',
])]
class AccountProfile extends Model
{
    protected $hidden = ['pin_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'account_profile_favorite_content')->withTimestamps();
    }

    public function hasPin(): bool
    {
        return ! empty($this->pin_hash);
    }

    public function checkPin(string $pin): bool
    {
        if (! $this->hasPin()) {
            return true;
        }

        return password_verify($pin, (string) $this->pin_hash);
    }

    protected function casts(): array
    {
        return [
            'is_kids' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
