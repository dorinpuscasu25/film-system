<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Offer;
use App\Models\Taxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;
use Throwable;

class ContentSearchService
{
    protected ?Client $client = null;

    protected bool $indexConfigured = false;

    public function searchCatalog(string $locale, array $filters = []): array
    {
        $page = max((int) Arr::get($filters, 'page', 1), 1);
        $pageSize = min(max((int) Arr::get($filters, 'page_size', 24), 1), 100);

        if ($this->shouldUseMeilisearch()) {
            try {
                return $this->searchWithMeilisearch($locale, $filters, $page, $pageSize);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->searchWithDatabase($locale, $filters, $page, $pageSize);
    }

    public function syncContent(Content|int|null $content): void
    {
        if (! $this->shouldUseMeilisearch() || $content === null) {
            return;
        }

        try {
            $this->configureIndex();
            $resolvedContent = $content instanceof Content
                ? $content->loadMissing('taxonomies', 'offers')
                : Content::query()->with('taxonomies', 'offers')->find($content);

            if ($resolvedContent === null || $resolvedContent->status !== Content::STATUS_PUBLISHED) {
                $this->deleteDocuments([$content instanceof Content ? $content->getKey() : $content]);

                return;
            }

            $this->index()->addDocuments([$this->makeDocument($resolvedContent)], 'id');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function syncContentIds(array $contentIds): void
    {
        $contentIds = collect($contentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if (! $this->shouldUseMeilisearch() || $contentIds->isEmpty()) {
            return;
        }

        try {
            $this->configureIndex();

            $contents = Content::query()
                ->with('taxonomies', 'offers')
                ->whereIn('id', $contentIds)
                ->get();

            $publishedContents = $contents
                ->filter(fn (Content $content): bool => $content->status === Content::STATUS_PUBLISHED)
                ->values();

            if ($publishedContents->isNotEmpty()) {
                $this->index()->addDocuments(
                    $publishedContents
                        ->map(fn (Content $content) => $this->makeDocument($content))
                        ->all(),
                    'id',
                );
            }

            $this->deleteDocuments($contentIds->diff($publishedContents->pluck('id')->map(fn ($id) => (int) $id))->all());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function reindex(): int
    {
        if (! $this->shouldUseMeilisearch()) {
            return 0;
        }

        $this->configureIndex();

        $deleteTask = $this->index()->deleteAllDocuments();
        $this->waitForTasks([$deleteTask]);

        $count = 0;

        Content::query()
            ->published()
            ->with('taxonomies', 'offers')
            ->orderBy('id')
            ->chunk(100, function ($contents) use (&$count): void {
                $documents = collect($contents)
                    ->map(fn (Content $content) => $this->makeDocument($content))
                    ->all();

                if ($documents === []) {
                    return;
                }

                $task = $this->index()->addDocuments($documents, 'id');
                $this->waitForTasks([$task]);
                $count += count($documents);
            });

        return $count;
    }

    protected function searchWithMeilisearch(string $locale, array $filters, int $page, int $pageSize): array
    {
        $searchParams = [
            'limit' => $pageSize,
            'offset' => ($page - 1) * $pageSize,
            'facets' => ['genre_slugs', 'release_year', 'country_code', 'type', 'is_free'],
            'sort' => ['is_featured:desc', 'sort_order:asc', 'release_year:desc', 'published_timestamp:desc'],
            'locales' => config("search.locales.{$locale}", [$locale]),
        ];

        $filterExpression = $this->buildMeilisearchFilterExpression($filters);
        if ($filterExpression !== null) {
            $searchParams['filter'] = $filterExpression;
        }

        $term = trim((string) Arr::get($filters, 'query', ''));
        $result = $this->index()->search($term !== '' ? $term : null, $searchParams);
        $contentIds = collect($result->getHits())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'items' => $this->loadContentsByOrderedIds($contentIds),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int) ($result->getEstimatedTotalHits() ?? 0),
            'filters' => $this->buildFacetPayload($result->getFacetDistribution(), $locale),
            'engine' => 'meilisearch',
        ];
    }

    protected function searchWithDatabase(string $locale, array $filters, int $page, int $pageSize): array
    {
        $query = $this->makeDatabaseQuery($filters);
        $matchedContents = (clone $query)
            ->with('taxonomies', 'offers')
            ->get()
            ->values();

        return [
            'items' => $matchedContents
                ->slice(($page - 1) * $pageSize, $pageSize)
                ->values(),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $matchedContents->count(),
            'filters' => $this->buildDatabaseFacetPayload($matchedContents, $locale),
            'engine' => 'database',
        ];
    }

    protected function makeDatabaseQuery(array $filters): Builder
    {
        $query = Content::query()
            ->published()
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('release_year')
            ->orderByDesc('published_at');

        $term = trim((string) Arr::get($filters, 'query', ''));
        if ($term !== '') {
            $likeOperator = $this->likeOperator();
            $likeTerm = '%'.$term.'%';

            $query->where(function (Builder $builder) use ($likeOperator, $likeTerm): void {
                $builder
                    ->where('slug', $likeOperator, $likeTerm)
                    ->orWhere('original_title', $likeOperator, $likeTerm);

                foreach (['title', 'tagline', 'short_description', 'description'] as $column) {
                    foreach ($this->localizedJsonExpressions($column) as $expression) {
                        $builder->orWhereRaw("{$expression} {$likeOperator} ?", [$likeTerm]);
                    }
                }

                $builder
                    ->orWhereRaw("CAST(cast_members AS TEXT) {$likeOperator} ?", [$likeTerm])
                    ->orWhereRaw("CAST(crew_members AS TEXT) {$likeOperator} ?", [$likeTerm])
                    ->orWhereRaw("CAST(videos AS TEXT) {$likeOperator} ?", [$likeTerm])
                    ->orWhereRaw("CAST(seasons AS TEXT) {$likeOperator} ?", [$likeTerm])
                    ->orWhereHas('taxonomies', function (Builder $taxonomyQuery) use ($likeOperator, $likeTerm): void {
                        $taxonomyQuery
                            ->where('active', true)
                            ->where(function (Builder $nested) use ($likeOperator, $likeTerm): void {
                                $nested->where('slug', $likeOperator, $likeTerm);

                                foreach ($this->localizedJsonExpressions('name') as $expression) {
                                    $nested->orWhereRaw("{$expression} {$likeOperator} ?", [$likeTerm]);
                                }
                            });
                    });
            });
        }

        $type = (string) Arr::get($filters, 'type', '');
        if (in_array($type, Content::availableTypes(), true)) {
            $query->where('type', $type);
        }

        $genre = trim((string) Arr::get($filters, 'genre', ''));
        if ($genre !== '') {
            $likeOperator = $this->likeOperator();
            $query->whereHas('taxonomies', function (Builder $builder) use ($genre, $likeOperator): void {
                $builder
                    ->where('type', Taxonomy::TYPE_GENRE)
                    ->where(function (Builder $nested) use ($genre, $likeOperator): void {
                        $nested->where('slug', $genre);

                        foreach ($this->localizedJsonExpressions('name') as $expression) {
                            $nested->orWhereRaw("{$expression} {$likeOperator} ?", ['%'.$genre.'%']);
                        }
                    });
            });
        }

        $access = (string) Arr::get($filters, 'access', '');
        if ($access === 'free') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->where('is_free', true)
                    ->orWhereHas('offers', fn (Builder $offerQuery) => $offerQuery->where('offer_type', Offer::TYPE_FREE));
            });
        }

        if ($access === 'paid') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->where('is_free', false)
                    ->whereDoesntHave('offers', fn (Builder $offerQuery) => $offerQuery->where('offer_type', Offer::TYPE_FREE));
            });
        }

        $year = (int) Arr::get($filters, 'year', 0);
        if ($year > 0) {
            $query->where('release_year', $year);
        }

        $countryCode = $this->resolveCountryCode(Arr::get($filters, 'country'));
        if ($countryCode !== null) {
            $query->where('country_code', $countryCode);
        }

        $minRating = (float) Arr::get($filters, 'min_rating', 0);
        if ($minRating > 0) {
            $query->where('imdb_rating', '>=', $minRating);
        }

        return $query;
    }

    protected function buildMeilisearchFilterExpression(array $filters): ?string
    {
        $expressions = [];

        $type = (string) Arr::get($filters, 'type', '');
        if (in_array($type, Content::availableTypes(), true)) {
            $expressions[] = sprintf('type = "%s"', $this->escapeFilterValue($type));
        }

        $genre = trim((string) Arr::get($filters, 'genre', ''));
        if ($genre !== '') {
            $expressions[] = sprintf('genre_slugs = "%s"', $this->escapeFilterValue($genre));
        }

        $access = (string) Arr::get($filters, 'access', '');
        if ($access === 'free') {
            $expressions[] = 'is_free = true';
        }

        if ($access === 'paid') {
            $expressions[] = 'is_free = false';
        }

        $year = (int) Arr::get($filters, 'year', 0);
        if ($year > 0) {
            $expressions[] = sprintf('release_year = %d', $year);
        }

        $countryCode = $this->resolveCountryCode(Arr::get($filters, 'country'));
        if ($countryCode !== null) {
            $expressions[] = sprintf('country_code = "%s"', $this->escapeFilterValue($countryCode));
        }

        $minRating = (float) Arr::get($filters, 'min_rating', 0);
        if ($minRating > 0) {
            $expressions[] = sprintf('imdb_rating >= %s', rtrim(rtrim(number_format($minRating, 2, '.', ''), '0'), '.'));
        }

        return $expressions !== [] ? implode(' AND ', $expressions) : null;
    }

    protected function buildFacetPayload(array $facetDistribution, string $locale): array
    {
        $countryOptions = Content::countryOptions();
        $typeLabels = Content::typeLabels();
        $genreCounts = collect($facetDistribution['genre_slugs'] ?? []);
        $genres = Taxonomy::query()
            ->where('type', Taxonomy::TYPE_GENRE)
            ->whereIn('slug', $genreCounts->keys()->all())
            ->get()
            ->keyBy('slug');

        return [
            'genres' => $genreCounts
                ->map(function ($count, $slug) use ($genres, $locale): array {
                    $taxonomy = $genres->get($slug);

                    return [
                        'value' => (string) $slug,
                        'label' => $taxonomy instanceof Taxonomy
                            ? $this->taxonomyLabel($taxonomy, $locale)
                            : (string) $slug,
                        'count' => (int) $count,
                    ];
                })
                ->sortByDesc('count')
                ->values(),
            'years' => collect($facetDistribution['release_year'] ?? [])
                ->map(fn ($count, $year): array => [
                    'value' => (string) $year,
                    'label' => (string) $year,
                    'count' => (int) $count,
                ])
                ->sortByDesc(fn (array $item) => (int) $item['value'])
                ->values(),
            'countries' => collect($facetDistribution['country_code'] ?? [])
                ->map(fn ($count, $code): array => [
                    'value' => (string) $code,
                    'label' => $countryOptions[$code] ?? (string) $code,
                    'count' => (int) $count,
                ])
                ->sortByDesc('count')
                ->values(),
            'types' => collect($facetDistribution['type'] ?? [])
                ->map(fn ($count, $type): array => [
                    'value' => (string) $type,
                    'label' => $typeLabels[$type] ?? ucfirst((string) $type),
                    'count' => (int) $count,
                ])
                ->values(),
            'access' => collect([
                [
                    'value' => 'free',
                    'label' => 'Free',
                    'count' => $this->boolFacetCount($facetDistribution['is_free'] ?? [], true),
                ],
                [
                    'value' => 'paid',
                    'label' => 'Paid',
                    'count' => $this->boolFacetCount($facetDistribution['is_free'] ?? [], false),
                ],
            ])->filter(fn (array $item): bool => $item['count'] > 0)->values(),
        ];
    }

    protected function buildDatabaseFacetPayload(Collection $contents, string $locale): array
    {
        $countryOptions = Content::countryOptions();
        $typeLabels = Content::typeLabels();
        $genreCounts = [];
        $genreLabels = [];
        $accessCounts = ['free' => 0, 'paid' => 0];

        $contents->each(function (Content $content) use (&$genreCounts, &$genreLabels, &$accessCounts, $locale): void {
            $isFree = (bool) $content->is_free
                || collect($content->offers ?? [])->contains(
                    fn ($offer): bool => $offer instanceof Offer && $offer->offer_type === Offer::TYPE_FREE,
                );

            $accessCounts[$isFree ? 'free' : 'paid']++;

            $content->taxonomies
                ->where('type', Taxonomy::TYPE_GENRE)
                ->unique('id')
                ->each(function (Taxonomy $taxonomy) use (&$genreCounts, &$genreLabels, $locale): void {
                    $genreCounts[$taxonomy->slug] = ($genreCounts[$taxonomy->slug] ?? 0) + 1;
                    $genreLabels[$taxonomy->slug] = $this->taxonomyLabel($taxonomy, $locale);
                });
        });

        return [
            'genres' => collect($genreCounts)
                ->map(fn ($count, $slug): array => [
                    'value' => (string) $slug,
                    'label' => $genreLabels[$slug] ?? (string) $slug,
                    'count' => (int) $count,
                ])
                ->sortByDesc('count')
                ->values(),
            'years' => $contents
                ->pluck('release_year')
                ->filter()
                ->countBy()
                ->map(fn ($count, $year): array => [
                    'value' => (string) $year,
                    'label' => (string) $year,
                    'count' => (int) $count,
                ])
                ->sortByDesc(fn (array $item) => (int) $item['value'])
                ->values(),
            'countries' => $contents
                ->pluck('country_code')
                ->filter()
                ->countBy()
                ->map(fn ($count, $code): array => [
                    'value' => (string) $code,
                    'label' => $countryOptions[$code] ?? (string) $code,
                    'count' => (int) $count,
                ])
                ->sortByDesc('count')
                ->values(),
            'types' => $contents
                ->pluck('type')
                ->filter()
                ->countBy()
                ->map(fn ($count, $type): array => [
                    'value' => (string) $type,
                    'label' => $typeLabels[$type] ?? ucfirst((string) $type),
                    'count' => (int) $count,
                ])
                ->values(),
            'access' => collect([
                [
                    'value' => 'free',
                    'label' => 'Free',
                    'count' => $accessCounts['free'],
                ],
                [
                    'value' => 'paid',
                    'label' => 'Paid',
                    'count' => $accessCounts['paid'],
                ],
            ])->filter(fn (array $item): bool => $item['count'] > 0)->values(),
        ];
    }

    protected function loadContentsByOrderedIds(array $contentIds): Collection
    {
        if ($contentIds === []) {
            return collect();
        }

        $contents = Content::query()
            ->published()
            ->with('taxonomies', 'offers')
            ->whereIn('id', $contentIds)
            ->get()
            ->keyBy('id');

        return collect($contentIds)
            ->map(fn (int $id) => $contents->get($id))
            ->filter()
            ->values();
    }

    protected function makeDocument(Content $content): array
    {
        $content->loadMissing('taxonomies', 'offers');

        $defaultLocale = in_array($content->default_locale, Content::supportedLocales(), true)
            ? $content->default_locale
            : Content::supportedLocales()[0];
        $taxonomies = $content->taxonomies->groupBy('type');
        $activeOffers = $content->offers
            ->filter(fn (Offer $offer): bool => $offer->isCurrentlyAvailable())
            ->values();
        $hasFreeOffer = $activeOffers->contains(fn (Offer $offer): bool => $offer->offer_type === Offer::TYPE_FREE);
        $isFree = (bool) $content->is_free || $hasFreeOffer;
        $qualities = $activeOffers->pluck('quality')->filter()->unique()->values();
        if ($qualities->isEmpty()) {
            $qualities = collect($content->available_qualities ?? [])->filter()->unique()->values();
        }

        $castMembers = collect($content->cast_members ?? []);
        $crewMembers = collect($content->crew_members ?? []);
        $videos = collect($content->videos ?? []);
        $seasons = collect($content->seasons ?? []);
        $episodeRecords = $seasons
            ->flatMap(fn (array $season) => data_get($season, 'episodes', []))
            ->values();

        $document = [
            'id' => (string) $content->getKey(),
            'slug' => $content->slug,
            'type' => $content->type,
            'status' => $content->status,
            'original_title' => $content->original_title,
            'release_year' => $content->release_year,
            'country_code' => $content->country_code,
            'country_name' => Content::countryOptions()[$content->country_code] ?? $content->country_code,
            'imdb_rating' => (float) ($content->imdb_rating ?? 0),
            'platform_rating' => (float) ($content->platform_rating ?? 0),
            'is_featured' => (bool) $content->is_featured,
            'is_trending' => (bool) $content->is_trending,
            'is_free' => $isFree,
            'lowest_price' => $isFree
                ? 0.0
                : (float) ($activeOffers->min('price_amount') ?? $content->price_amount ?? 0),
            'currency' => $content->currency ?: Content::DEFAULT_CURRENCY,
            'available_qualities' => $qualities->all(),
            'offer_types' => $activeOffers->pluck('offer_type')->filter()->unique()->values()->all(),
            'genre_slugs' => $taxonomies->get(Taxonomy::TYPE_GENRE, collect())->pluck('slug')->values()->all(),
            'collection_slugs' => $taxonomies->get(Taxonomy::TYPE_COLLECTION, collect())->pluck('slug')->values()->all(),
            'tag_slugs' => $taxonomies->get(Taxonomy::TYPE_TAG, collect())->pluck('slug')->values()->all(),
            'badge_slugs' => $taxonomies->get(Taxonomy::TYPE_BADGE, collect())->pluck('slug')->values()->all(),
            'cast_names' => $castMembers->pluck('name')->filter()->unique()->values()->all(),
            'crew_names' => $crewMembers->pluck('name')->filter()->unique()->values()->all(),
            'sort_order' => (int) ($content->sort_order ?? 0),
            'published_timestamp' => $content->published_at?->timestamp
                ?? $content->updated_at?->timestamp
                ?? $content->created_at?->timestamp
                ?? now()->timestamp,
        ];

        foreach (Content::supportedLocales() as $locale) {
            $title = $content->getTranslation('title', $locale, false)
                ?? $content->getTranslation('title', $defaultLocale, false)
                ?? $content->original_title;
            $tagline = $content->getTranslation('tagline', $locale, false)
                ?? $content->getTranslation('tagline', $defaultLocale, false)
                ?? '';
            $shortDescription = $content->getTranslation('short_description', $locale, false)
                ?? $content->getTranslation('short_description', $defaultLocale, false)
                ?? '';
            $description = $content->getTranslation('description', $locale, false)
                ?? $content->getTranslation('description', $defaultLocale, false)
                ?? '';

            $genreNames = $this->localizedTaxonomyNames($taxonomies->get(Taxonomy::TYPE_GENRE, collect()), $locale);
            $collectionNames = $this->localizedTaxonomyNames($taxonomies->get(Taxonomy::TYPE_COLLECTION, collect()), $locale);
            $tagNames = $this->localizedTaxonomyNames($taxonomies->get(Taxonomy::TYPE_TAG, collect()), $locale);
            $badgeNames = $this->localizedTaxonomyNames($taxonomies->get(Taxonomy::TYPE_BADGE, collect()), $locale);
            $castRoles = $this->localizedNestedValues($castMembers, $locale, $defaultLocale, ['character_name', 'role']);
            $crewJobs = $this->localizedNestedValues($crewMembers, $locale, $defaultLocale, ['job_title', 'job']);
            $videoTitles = $this->localizedNestedValues($videos, $locale, $defaultLocale, ['title']);
            $seasonTitles = $this->localizedNestedValues($seasons, $locale, $defaultLocale, ['title']);
            $episodeTitles = $this->localizedNestedValues($episodeRecords, $locale, $defaultLocale, ['title']);

            $document["title_{$locale}"] = $title;
            $document["tagline_{$locale}"] = $tagline;
            $document["short_description_{$locale}"] = $shortDescription;
            $document["description_{$locale}"] = $description;
            $document["genre_names_{$locale}"] = $genreNames;
            $document["collection_names_{$locale}"] = $collectionNames;
            $document["tag_names_{$locale}"] = $tagNames;
            $document["badge_names_{$locale}"] = $badgeNames;
            $document["cast_roles_{$locale}"] = $castRoles;
            $document["crew_jobs_{$locale}"] = $crewJobs;
            $document["video_titles_{$locale}"] = $videoTitles;
            $document["season_titles_{$locale}"] = $seasonTitles;
            $document["episode_titles_{$locale}"] = $episodeTitles;
            $document["search_blob_{$locale}"] = trim(implode(' ', array_filter([
                $title,
                $tagline,
                $shortDescription,
                $description,
                $content->original_title,
                $content->slug,
                implode(' ', $genreNames),
                implode(' ', $collectionNames),
                implode(' ', $tagNames),
                implode(' ', $badgeNames),
                implode(' ', $document['cast_names']),
                implode(' ', $castRoles),
                implode(' ', $document['crew_names']),
                implode(' ', $crewJobs),
                implode(' ', $videoTitles),
                implode(' ', $seasonTitles),
                implode(' ', $episodeTitles),
            ])));
        }

        return $document;
    }

    protected function localizedTaxonomyNames(Collection $taxonomies, string $locale): array
    {
        return $taxonomies
            ->map(fn (Taxonomy $taxonomy) => $this->taxonomyLabel($taxonomy, $locale))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function localizedNestedValues(
        Collection $records,
        string $locale,
        string $defaultLocale,
        array $keys,
    ): array {
        return $records
            ->map(function (array $record) use ($keys, $locale, $defaultLocale): ?string {
                foreach ($keys as $key) {
                    $value = data_get($record, $key);
                    $resolved = $this->resolveLocalizedValue($value, $locale, $defaultLocale);

                    if ($resolved !== null) {
                        return $resolved;
                    }
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveLocalizedValue(mixed $value, string $locale, string $defaultLocale): ?string
    {
        if (is_array($value)) {
            $resolved = $value[$locale] ?? $value[$defaultLocale] ?? reset($value) ?: null;

            return $this->normalizeString($resolved);
        }

        return $this->normalizeString($value);
    }

    protected function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function configureIndex(): void
    {
        if ($this->indexConfigured || ! $this->shouldUseMeilisearch()) {
            return;
        }

        $index = $this->index();
        $tasks = [
            $index->updateSearchableAttributes([
                'title_ro',
                'title_ru',
                'title_en',
                'original_title',
                'slug',
                'genre_names_ro',
                'genre_names_ru',
                'genre_names_en',
                'collection_names_ro',
                'collection_names_ru',
                'collection_names_en',
                'tag_names_ro',
                'tag_names_ru',
                'tag_names_en',
                'badge_names_ro',
                'badge_names_ru',
                'badge_names_en',
                'cast_names',
                'crew_names',
                'cast_roles_ro',
                'cast_roles_ru',
                'cast_roles_en',
                'crew_jobs_ro',
                'crew_jobs_ru',
                'crew_jobs_en',
                'video_titles_ro',
                'video_titles_ru',
                'video_titles_en',
                'season_titles_ro',
                'season_titles_ru',
                'season_titles_en',
                'episode_titles_ro',
                'episode_titles_ru',
                'episode_titles_en',
                'search_blob_ro',
                'search_blob_ru',
                'search_blob_en',
            ]),
            $index->updateFilterableAttributes([
                'type',
                'genre_slugs',
                'release_year',
                'country_code',
                'is_free',
            ]),
            $index->updateSortableAttributes([
                'is_featured',
                'sort_order',
                'release_year',
                'published_timestamp',
                'imdb_rating',
                'lowest_price',
            ]),
            $index->updateLocalizedAttributes([
                [
                    'attributePatterns' => ['*_ro'],
                    'locales' => config('search.locales.ro', ['ron']),
                ],
                [
                    'attributePatterns' => ['*_ru'],
                    'locales' => config('search.locales.ru', ['rus']),
                ],
                [
                    'attributePatterns' => ['*_en'],
                    'locales' => config('search.locales.en', ['eng']),
                ],
            ]),
        ];

        $this->waitForTasks($tasks);
        $this->indexConfigured = true;
    }

    protected function waitForTasks(array $tasks): void
    {
        foreach ($tasks as $task) {
            $taskUid = data_get($task, 'taskUid');

            if ($taskUid !== null) {
                $this->client()->waitForTask($taskUid, 10000, 100);
            }
        }
    }

    protected function deleteDocuments(array $contentIds): void
    {
        $contentIds = collect($contentIds)
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($contentIds === []) {
            return;
        }

        try {
            $this->index()->deleteDocuments($contentIds);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function taxonomyLabel(Taxonomy $taxonomy, string $locale): string
    {
        return $taxonomy->getTranslation('name', $locale, false)
            ?? $taxonomy->getTranslation('name', Taxonomy::LOCALE_RO, false)
            ?? $taxonomy->slug;
    }

    protected function boolFacetCount(array $distribution, bool $value): int
    {
        $variants = $value
            ? ['true', '1', 1, true]
            : ['false', '0', 0, false];

        foreach ($variants as $variant) {
            if (array_key_exists($variant, $distribution)) {
                return (int) $distribution[$variant];
            }
        }

        return 0;
    }

    protected function resolveCountryCode(mixed $value): ?string
    {
        $resolved = strtoupper(trim((string) $value));
        if ($resolved === '') {
            return null;
        }

        if (array_key_exists($resolved, Content::countryOptions())) {
            return $resolved;
        }

        foreach (Content::countryOptions() as $code => $label) {
            if (mb_strtolower($label) === mb_strtolower((string) $value)) {
                return $code;
            }
        }

        return null;
    }

    protected function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    protected function localizedJsonExpressions(string $column): array
    {
        return collect(Content::supportedLocales())
            ->map(fn (string $locale) => $this->jsonTextExpression($column, $locale))
            ->all();
    }

    protected function jsonTextExpression(string $column, string $locale): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? sprintf("%s->>'%s'", $column, $locale)
            : sprintf("json_extract(%s, '$.\"%s\"')", $column, $locale);
    }

    protected function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    protected function shouldUseMeilisearch(): bool
    {
        return config('search.driver') === 'meilisearch'
            && filled(config('search.meilisearch.host'));
    }

    protected function index()
    {
        return $this->client()->index($this->indexUid());
    }

    protected function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client(
                (string) config('search.meilisearch.host'),
                config('search.meilisearch.key'),
            );
        }

        return $this->client;
    }

    protected function indexUid(): string
    {
        return (string) config('search.indexes.content.uid');
    }
}
