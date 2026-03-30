<?php

namespace App\Services;

use App\Models\Content;
use App\Models\HomePageSection;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HomePageService
{
    public function listSections(): Collection
    {
        return HomePageSection::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function activeSections(): Collection
    {
        return HomePageSection::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function replaceSections(array $sections): Collection
    {
        return DB::transaction(function () use ($sections): Collection {
            $persistedIds = [];

            foreach (array_values($sections) as $index => $payload) {
                $sectionId = data_get($payload, 'id');
                $section = $sectionId
                    ? HomePageSection::query()->find($sectionId)
                    : null;

                if (! $section instanceof HomePageSection) {
                    $section = new HomePageSection();
                }

                $section->fill([
                    'name' => data_get($payload, 'name'),
                    'section_type' => data_get($payload, 'section_type'),
                    'active' => (bool) data_get($payload, 'active', true),
                    'sort_order' => (int) data_get($payload, 'sort_order', $index),
                    'title' => data_get($payload, 'title'),
                    'subtitle' => data_get($payload, 'subtitle'),
                    'source_mode' => data_get($payload, 'source_mode'),
                    'limit' => data_get($payload, 'limit'),
                    'content_ids' => data_get($payload, 'content_ids', []),
                    'rule_filters' => data_get($payload, 'rule_filters', []),
                    'hero_slides' => data_get($payload, 'hero_slides', []),
                    'meta' => data_get($payload, 'meta', []),
                ])->save();

                $persistedIds[] = $section->id;
            }

            if ($persistedIds === []) {
                HomePageSection::query()->delete();
            } else {
                HomePageSection::query()
                    ->whereNotIn('id', $persistedIds)
                    ->delete();
            }

            return $this->listSections();
        });
    }

    public function resolveHeroSlides(HomePageSection $section): Collection
    {
        $slides = collect($section->hero_slides ?? [])
            ->filter(fn (array $slide): bool => (bool) data_get($slide, 'active', true))
            ->sortBy('sort_order')
            ->values();

        if ($slides->isEmpty()) {
            return collect();
        }

        $contentIds = $slides->pluck('content_id')
            ->map(fn ($value): int => (int) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $contentMap = $this->publishedContentQuery()
            ->whereIn('id', $contentIds)
            ->get()
            ->keyBy('id');

        return $slides
            ->map(function (array $slide) use ($contentMap): ?array {
                $content = $contentMap->get((int) data_get($slide, 'content_id'));

                if (! $content instanceof Content) {
                    return null;
                }

                return [
                    'id' => (string) data_get($slide, 'id'),
                    'content_id' => $content->id,
                    'active' => (bool) data_get($slide, 'active', true),
                    'sort_order' => (int) data_get($slide, 'sort_order', 0),
                    'desktop_image_url' => data_get($slide, 'desktop_image_url'),
                    'mobile_image_url' => data_get($slide, 'mobile_image_url'),
                    'eyebrow' => $this->normalizedLocalizedValue(data_get($slide, 'eyebrow')),
                    'title' => $this->normalizedLocalizedValue(data_get($slide, 'title')),
                    'description' => $this->normalizedLocalizedValue(data_get($slide, 'description')),
                    'primary_cta_label' => $this->normalizedLocalizedValue(data_get($slide, 'primary_cta_label')),
                    'secondary_cta_label' => $this->normalizedLocalizedValue(data_get($slide, 'secondary_cta_label')),
                    'content' => $content,
                ];
            })
            ->filter()
            ->values();
    }

    public function resolveCarouselItems(HomePageSection $section): Collection
    {
        if ($section->source_mode === HomePageSection::SOURCE_MANUAL) {
            return $this->resolveManualItems($section);
        }

        return $this->resolveDynamicItems($section);
    }

    protected function resolveManualItems(HomePageSection $section): Collection
    {
        $contentIds = collect($section->content_ids ?? [])
            ->map(fn ($value): int => (int) $value)
            ->filter()
            ->unique()
            ->values();

        if ($contentIds->isEmpty()) {
            return collect();
        }

        $items = $this->publishedContentQuery()
            ->whereIn('id', $contentIds->all())
            ->get()
            ->sortBy(fn (Content $content): int => $contentIds->search($content->id))
            ->values();

        return $section->limit
            ? $items->take((int) $section->limit)->values()
            : $items;
    }

    protected function resolveDynamicItems(HomePageSection $section): Collection
    {
        $filters = $section->rule_filters ?? [];
        $taxonomyIds = collect(data_get($filters, 'taxonomy_ids', []))
            ->map(fn ($value): int => (int) $value)
            ->filter()
            ->unique()
            ->values();
        $contentTypes = collect(data_get($filters, 'content_types', []))
            ->map(fn ($value): string => (string) $value)
            ->filter(fn (string $value): bool => in_array($value, Content::availableTypes(), true))
            ->unique()
            ->values();
        $matchingStrategy = (string) data_get($filters, 'matching_strategy', HomePageSection::MATCH_ANY);
        $accessMode = (string) data_get($filters, 'access', HomePageSection::ACCESS_ALL);
        $sortMode = (string) data_get($filters, 'sort_mode', HomePageSection::SORT_RELEASE_YEAR_DESC);

        $query = $this->publishedContentQuery();

        if ($contentTypes->isNotEmpty()) {
            $query->whereIn('type', $contentTypes->all());
        }

        if ($taxonomyIds->isNotEmpty()) {
            if ($matchingStrategy === HomePageSection::MATCH_ALL) {
                $taxonomyIds->each(function (int $taxonomyId) use ($query): void {
                    $query->whereHas('taxonomies', fn (Builder $builder) => $builder->where('taxonomies.id', $taxonomyId));
                });
            } else {
                $query->whereHas('taxonomies', fn (Builder $builder) => $builder->whereIn('taxonomies.id', $taxonomyIds->all()));
            }
        }

        if ($accessMode === HomePageSection::ACCESS_FREE) {
            $query->where(function (Builder $builder): void {
                $builder
                    ->where('is_free', true)
                    ->orWhereHas('offers', fn (Builder $offerQuery) => $offerQuery->where('offer_type', Offer::TYPE_FREE));
            });
        }

        if ($accessMode === HomePageSection::ACCESS_PAID) {
            $query->where(function (Builder $builder): void {
                $builder
                    ->where('price_amount', '>', 0)
                    ->orWhereHas('offers', fn (Builder $offerQuery) => $offerQuery->whereIn('offer_type', [
                        Offer::TYPE_RENTAL,
                        Offer::TYPE_LIFETIME,
                    ]));
            });
        }

        if ((bool) data_get($filters, 'featured_only', false)) {
            $query->where('is_featured', true);
        }

        if ((bool) data_get($filters, 'trending_only', false)) {
            $query->where('is_trending', true);
        }

        match ($sortMode) {
            HomePageSection::SORT_RELEASE_YEAR_ASC => $query->orderBy('release_year')->orderBy('original_title'),
            HomePageSection::SORT_PUBLISHED_DESC => $query->orderByDesc('published_at')->orderByDesc('release_year'),
            HomePageSection::SORT_IMDB_DESC => $query->orderByDesc('imdb_rating')->orderByDesc('release_year'),
            HomePageSection::SORT_PLATFORM_DESC => $query->orderByDesc('platform_rating')->orderByDesc('release_year'),
            HomePageSection::SORT_TITLE_ASC => $query->orderBy('original_title'),
            default => $query->orderByDesc('release_year')->orderBy('sort_order')->orderByDesc('published_at'),
        };

        if ($section->limit) {
            $query->limit((int) $section->limit);
        }

        return $query->get();
    }

    protected function publishedContentQuery(): Builder
    {
        return Content::query()
            ->published()
            ->with('taxonomies', 'offers');
    }

    protected function normalizedLocalizedValue(mixed $value): array
    {
        if (! is_array($value)) {
            $stringValue = trim((string) $value);

            return collect(Content::supportedLocales())
                ->mapWithKeys(fn (string $locale) => [$locale => $stringValue])
                ->all();
        }

        return collect(Content::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($value[$locale] ?? ''))])
            ->all();
    }
}
