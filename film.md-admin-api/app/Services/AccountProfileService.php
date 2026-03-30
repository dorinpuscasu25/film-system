<?php

namespace App\Services;

use App\Models\AccountProfile;
use App\Models\Content;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class AccountProfileService
{
    protected array $defaultColors = [
        'from-blue-500 to-purple-600',
        'from-pink-500 to-rose-500',
        'from-green-400 to-emerald-600',
        'from-orange-500 to-red-500',
        'from-cyan-500 to-blue-600',
        'from-violet-500 to-purple-600',
    ];

    public function ensureDefaultProfile(User $user): AccountProfile
    {
        $existingProfile = $user->profiles()->orderByDesc('is_default')->orderBy('sort_order')->first();
        if ($existingProfile !== null) {
            if (! $user->profiles()->where('is_default', true)->exists()) {
                $existingProfile->forceFill(['is_default' => true])->save();
            }

            return $existingProfile;
        }

        return $user->profiles()->create([
            'name' => trim((string) $user->name) !== '' ? $user->name : $user->email,
            'avatar_label' => mb_strtoupper(mb_substr(trim((string) $user->name) !== '' ? $user->name : $user->email, 0, 1)),
            'avatar_color' => $this->defaultColorForUser($user->id),
            'is_kids' => false,
            'is_default' => true,
            'sort_order' => 0,
        ]);
    }

    public function create(User $user, array $payload): AccountProfile
    {
        if ($user->profiles()->count() >= 5) {
            throw ValidationException::withMessages([
                'profiles' => ['You can create up to 5 profiles on one account.'],
            ]);
        }

        return $user->profiles()->create([
            'name' => trim((string) $payload['name']),
            'avatar_label' => $payload['avatar_label'] ?? mb_strtoupper(mb_substr(trim((string) $payload['name']), 0, 1)),
            'avatar_color' => $payload['avatar_color'] ?? $this->defaultColorForUser($user->id + $user->profiles()->count()),
            'is_kids' => (bool) ($payload['is_kids'] ?? false),
            'is_default' => ! $user->profiles()->exists(),
            'sort_order' => ((int) $user->profiles()->max('sort_order')) + 10,
        ]);
    }

    public function update(AccountProfile $profile, array $payload): AccountProfile
    {
        $profile->fill([
            'name' => trim((string) $payload['name']),
            'avatar_label' => $payload['avatar_label'] ?? mb_strtoupper(mb_substr(trim((string) $payload['name']), 0, 1)),
            'avatar_color' => $payload['avatar_color'] ?? $profile->avatar_color,
            'is_kids' => (bool) ($payload['is_kids'] ?? false),
        ]);
        $profile->save();

        return $profile->fresh();
    }

    public function delete(AccountProfile $profile): void
    {
        $user = $profile->user;

        if ($user->profiles()->count() <= 1) {
            throw ValidationException::withMessages([
                'profiles' => ['At least one profile must remain on the account.'],
            ]);
        }

        $wasDefault = $profile->is_default;
        $profile->favorites()->detach();
        $profile->delete();

        if ($wasDefault) {
            $replacement = $user->profiles()->orderBy('sort_order')->first();
            if ($replacement !== null) {
                $replacement->forceFill(['is_default' => true])->save();
            }
        }
    }

    public function toggleFavorite(AccountProfile $profile, Content $content, bool $favorite): void
    {
        if ($favorite) {
            $profile->favorites()->syncWithoutDetaching([$content->id]);
            return;
        }

        $profile->favorites()->detach([$content->id]);
    }

    protected function defaultColorForUser(int $seed): string
    {
        return $this->defaultColors[$seed % count($this->defaultColors)];
    }
}
