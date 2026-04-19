<?php

namespace App\Services;

use App\Models\Content;
use App\Models\WatchProgress;
use Illuminate\Support\Collection;

class RecommendationService
{
    public function related(Content $content, ?int $userId = null, int $limit = 8): Collection
    {
        $genreIds = $content->taxonomies()->where('type', 'genre')->pluck('taxonomies.id');
        $query = Content::query()
            ->published()
            ->where('id', '!=', $content->id)
            ->with('taxonomies');

        if ($genreIds->isNotEmpty()) {
            $query->whereHas('taxonomies', fn ($builder) => $builder->whereIn('taxonomies.id', $genreIds));
        }

        $items = $query
            ->orderByDesc('is_trending')
            ->orderByDesc('platform_rating')
            ->limit($limit)
            ->get();

        if ($userId === null || $items->count() >= $limit) {
            return $items;
        }

        $recentContentIds = WatchProgress::query()
            ->where('user_id', $userId)
            ->latest('last_watched_at')
            ->limit($limit)
            ->pluck('content_id');

        if ($recentContentIds->isEmpty()) {
            return $items;
        }

        $fallback = Content::query()
            ->published()
            ->whereIn('id', $recentContentIds)
            ->whereNotIn('id', $items->pluck('id'))
            ->limit($limit - $items->count())
            ->get();

        return $items->concat($fallback)->take($limit)->values();
    }
}
