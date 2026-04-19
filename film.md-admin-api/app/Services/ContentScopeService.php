<?php

namespace App\Services;

use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ContentScopeService
{
    public function isScoped(?User $user): bool
    {
        return $user?->hasScopedContentAccess() ?? false;
    }

    public function assignedContentIds(?User $user): array
    {
        if ($user === null || ! $this->isScoped($user)) {
            return [];
        }

        return $user->assignedContentIds();
    }

    public function scopeContentQuery(?User $user, Builder $query, string $column = 'contents.id'): Builder
    {
        if (! $this->isScoped($user)) {
            return $query;
        }

        $assignedIds = $this->assignedContentIds($user);

        if ($assignedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $assignedIds);
    }

    public function assertCanAccessContent(?User $user, Content|int $content): void
    {
        if (! $this->canAccessContent($user, $content)) {
            throw new HttpException(403, 'Nu ai acces la acest film.');
        }
    }

    public function canAccessContent(?User $user, Content|int $content): bool
    {
        if ($user === null || ! $this->isScoped($user)) {
            return true;
        }

        $contentId = $content instanceof Content ? (int) $content->getKey() : (int) $content;

        return in_array($contentId, $this->assignedContentIds($user), true);
    }
}
