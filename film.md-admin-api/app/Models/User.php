<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'preferred_locale', 'status', 'avatar_url', 'last_seen_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(ContentEntitlement::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(AccountProfile::class)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id');
    }

    public function contentAccesses(): HasMany
    {
        return $this->hasMany(UserContentAccess::class)->orderBy('content_id');
    }

    public function contentReviews(): HasMany
    {
        return $this->hasMany(ContentReview::class);
    }

    public function assignedContents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'user_content_accesses')
            ->withPivot(['can_view', 'can_view_stats', 'meta'])
            ->withTimestamps()
            ->orderBy('original_title');
    }

    public function roleNames(): array
    {
        return $this->roles()
            ->pluck('name')
            ->all();
    }

    public function permissionCodes(): array
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->push(...($this->roles->contains('admin_panel_access', true) ? ['admin.access'] : []))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $permissionCode): bool
    {
        return in_array($permissionCode, $this->permissionCodes(), true);
    }

    public function hasAdminPanelAccess(): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains('admin_panel_access', true) || $this->hasPermission('admin.access');
    }

    public function hasScopedContentAccess(): bool
    {
        return $this->hasPermission('content.scope_assigned');
    }

    public function assignedContentIds(): array
    {
        $direct = $this->relationLoaded('contentAccesses')
            ? $this->contentAccesses
                ->where('can_view', true)
                ->pluck('content_id')
                ->map(fn (mixed $value): int => (int) $value)
                ->values()
                ->all()
            : $this->contentAccesses()
                ->where('can_view', true)
                ->pluck('content_id')
                ->map(fn (mixed $value): int => (int) $value)
                ->all();

        $creatorIds = $this->creatorAssignedContentIds();

        return array_values(array_unique(array_merge($direct, $creatorIds)));
    }

    /**
     * Resolves content IDs assigned via the creator portal:
     * users linked to a content_creators row inherit all contents assigned
     * to that creator through content_creator_assignments.
     *
     * @return array<int, int>
     */
    public function creatorAssignedContentIds(): array
    {
        $creator = \App\Models\ContentCreator::query()
            ->where('user_id', $this->getKey())
            ->where('is_active', true)
            ->first();

        if ($creator === null) {
            return [];
        }

        return \App\Models\ContentCreator::query()
            ->where('id', $creator->id)
            ->with('contents:id')
            ->get()
            ->flatMap(fn (\App\Models\ContentCreator $c) => $c->contents->pluck('id'))
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }

    public function syncRoleIds(array $roleIds): void
    {
        $this->roles()->sync(array_values(array_unique($roleIds)));
        $this->unsetRelation('roles');
    }

    public function syncAssignedContentIds(array $contentIds): void
    {
        $syncPayload = collect($contentIds)
            ->map(fn (mixed $contentId): int => (int) $contentId)
            ->filter(fn (int $contentId): bool => $contentId > 0)
            ->unique()
            ->mapWithKeys(fn (int $contentId): array => [
                $contentId => [
                    'can_view' => true,
                    'can_view_stats' => true,
                    'meta' => null,
                ],
            ])
            ->all();

        $this->assignedContents()->sync($syncPayload);
        $this->unsetRelation('contentAccesses');
        $this->unsetRelation('assignedContents');
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => trim((string) $this->name) ?: (string) $this->email);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
