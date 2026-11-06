<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'blocked_user_id'])]
class UserBlock extends Model
{
    /** @return array<int, int> */
    public static function blockedIdsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return static::query()->where('user_id', $user->id)->pluck('blocked_user_id')->map(fn ($id) => (int) $id)->all();
    }
}
