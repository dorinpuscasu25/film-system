<?php

namespace App\Http\Requests\Admin;

use App\Models\Content;
use App\Models\Taxonomy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Content::availableTypes())],
            'slug' => ['required', 'string', 'max:255', Rule::unique('contents', 'slug')],
            'default_locale' => ['required', Rule::in(Content::supportedLocales())],
            'status' => ['required', Rule::in(Content::availableStatuses())],
            'original_title' => ['required', 'string', 'max:255'],
            'release_year' => ['nullable', 'integer', 'between:1900,2100'],
            'country_code' => ['nullable', Rule::in(array_keys(Content::countryOptions()))],
            'imdb_rating' => ['nullable', 'numeric', 'between:0,10'],
            'platform_rating' => ['nullable', 'numeric', 'between:0,5'],
            'runtime_minutes' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'age_rating' => ['nullable', Rule::in(Content::availableAgeRatings())],
            'poster_url' => $this->imageAssetRules(true),
            'backdrop_url' => $this->imageAssetRules(true),
            'hero_desktop_url' => $this->imageAssetRules(false),
            'hero_mobile_url' => $this->imageAssetRules(false),
            'trailer_url' => ['nullable', 'url', 'max:2048'],
            'preview_images' => ['nullable', 'array'],
            'preview_images.*' => $this->imageAssetRules(false),
            'cast_members' => ['nullable', 'array'],
            'cast_members.*.id' => ['nullable', 'string', 'max:64'],
            'cast_members.*.name' => ['required', 'string', 'max:255'],
            'cast_members.*.credit_type' => ['required', Rule::in(array_keys(Content::castCreditTypeTranslations()))],
            'cast_members.*.avatar_url' => $this->imageAssetRules(false),
            'cast_members.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'crew_members' => ['nullable', 'array'],
            'crew_members.*.id' => ['nullable', 'string', 'max:64'],
            'crew_members.*.name' => ['required', 'string', 'max:255'],
            'crew_members.*.credit_type' => ['required', Rule::in(array_keys(Content::crewCreditTypeTranslations()))],
            'crew_members.*.avatar_url' => $this->imageAssetRules(false),
            'crew_members.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'videos' => ['nullable', 'array'],
            'videos.*.id' => ['nullable', 'string', 'max:64'],
            'videos.*.type' => ['required', Rule::in(array_keys(Content::videoTypeLabels()))],
            'videos.*.video_url' => ['required', 'url', 'max:2048'],
            'videos.*.thumbnail_url' => $this->imageAssetRules(false),
            'videos.*.duration_seconds' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'videos.*.is_primary' => ['nullable', 'boolean'],
            'videos.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'seasons' => ['nullable', 'array'],
            'seasons.*.id' => ['nullable', 'string', 'max:64'],
            'seasons.*.season_number' => ['required', 'integer', 'min:1', 'max:100'],
            'seasons.*.poster_url' => $this->imageAssetRules(false),
            'seasons.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'seasons.*.episodes' => ['nullable', 'array'],
            'seasons.*.episodes.*.id' => ['nullable', 'string', 'max:64'],
            'seasons.*.episodes.*.episode_number' => ['required', 'integer', 'min:1', 'max:1000'],
            'seasons.*.episodes.*.runtime_minutes' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'seasons.*.episodes.*.thumbnail_url' => $this->imageAssetRules(false),
            'seasons.*.episodes.*.backdrop_url' => $this->imageAssetRules(false),
            'seasons.*.episodes.*.video_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.trailer_url' => ['nullable', 'url', 'max:2048'],
            'seasons.*.episodes.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'subtitle_locales' => ['nullable', 'array'],
            'subtitle_locales.*' => [Rule::in(Content::supportedLocales())],
            'available_qualities' => ['required', 'array', 'min:1'],
            'available_qualities.*' => [Rule::in(Content::availableQualities())],
            'content_formats' => ['nullable', 'array'],
            'content_formats.*.id' => ['nullable', 'integer'],
            'content_formats.*.quality' => ['required', Rule::in(Content::availableQualities())],
            'content_formats.*.format_type' => ['required', Rule::in(['main', 'trailer'])],
            'content_formats.*.bunny_library_id' => ['required', 'string', 'max:64'],
            'content_formats.*.bunny_video_id' => ['required', 'string', 'max:128'],
            'content_formats.*.stream_url' => ['nullable', 'url', 'max:2048'],
            'content_formats.*.token_path' => ['nullable', 'string', 'max:255'],
            'content_formats.*.drm_policy' => ['nullable', 'string', 'max:32'],
            'content_formats.*.is_active' => ['sometimes', 'boolean'],
            'content_formats.*.is_default' => ['sometimes', 'boolean'],
            'content_formats.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'content_formats.*.meta' => ['nullable', 'array'],
            'rights_windows' => ['nullable', 'array'],
            'rights_windows.*.id' => ['nullable', 'integer'],
            'rights_windows.*.content_format_quality' => ['nullable', Rule::in(Content::availableQualities())],
            'rights_windows.*.country_code' => ['nullable', Rule::in(array_keys(Content::countryOptions()))],
            'rights_windows.*.is_allowed' => ['sometimes', 'boolean'],
            'rights_windows.*.starts_at' => ['nullable', 'date'],
            'rights_windows.*.ends_at' => ['nullable', 'date', 'after:rights_windows.*.starts_at'],
            'rights_windows.*.meta' => ['nullable', 'array'],
            'subtitle_tracks' => ['nullable', 'array'],
            'subtitle_tracks.*.id' => ['nullable', 'integer'],
            'subtitle_tracks.*.content_format_quality' => ['nullable', Rule::in(Content::availableQualities())],
            'subtitle_tracks.*.locale' => [Rule::in(Content::supportedLocales())],
            'subtitle_tracks.*.label' => ['required', 'string', 'max:64'],
            'subtitle_tracks.*.file_url' => ['required', 'url', 'max:2048'],
            'subtitle_tracks.*.is_default' => ['sometimes', 'boolean'],
            'subtitle_tracks.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'premiere_events' => ['nullable', 'array'],
            'premiere_events.*.id' => ['nullable', 'integer'],
            'premiere_events.*.title' => ['required', 'string', 'max:255'],
            'premiere_events.*.starts_at' => ['required', 'date'],
            'premiere_events.*.ends_at' => ['nullable', 'date'],
            'premiere_events.*.is_active' => ['sometimes', 'boolean'],
            'premiere_events.*.is_public' => ['sometimes', 'boolean'],
            'premiere_events.*.meta' => ['nullable', 'array'],
            'creator_ids' => ['nullable', 'array'],
            'creator_ids.*' => ['integer', Rule::exists('content_creators', 'id')],
            'is_featured' => ['sometimes', 'boolean'],
            'is_trending' => ['sometimes', 'boolean'],
            'is_free' => ['sometimes', 'boolean'],
            'price_amount' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'rental_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'taxonomy_ids' => ['nullable', 'array'],
            'taxonomy_ids.*' => ['integer', Rule::exists('taxonomies', 'id')],
            ...$this->localizedRules('title', true, 255),
            ...$this->localizedRules('tagline', false, 255),
            ...$this->localizedRules('short_description', true, 400),
            ...$this->localizedRules('description', true, 5000),
            ...$this->localizedRules('editor_notes', false, 2000),
            ...$this->localizedRules('meta_title', false, 255),
            ...$this->localizedRules('meta_description', false, 400),
            ...$this->localizedRules('cast_members.*.character_name', true, 255),
            ...$this->localizedRules('crew_members.*.job_title', true, 255),
            ...$this->localizedRules('videos.*.title', true, 255),
            ...$this->localizedRules('videos.*.description', false, 2000),
            ...$this->localizedRules('seasons.*.title', false, 255),
            ...$this->localizedRules('seasons.*.description', false, 5000),
            ...$this->localizedRules('seasons.*.episodes.*.title', true, 255),
            ...$this->localizedRules('seasons.*.episodes.*.description', false, 5000),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('taxonomy_ids')) {
                $taxonomyIds = collect($this->input('taxonomy_ids', []))
                    ->map(fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values();

                $resolvedIds = Taxonomy::query()
                    ->whereIn('id', $taxonomyIds)
                    ->pluck('id');

                if ($resolvedIds->count() !== $taxonomyIds->count()) {
                    $validator->errors()->add('taxonomy_ids', 'One or more taxonomy selections are invalid.');
                }
            }
        });
    }

    public function normalizedPayload(): array
    {
        $status = (string) $this->input('status');
        $publishedAt = $status === Content::STATUS_PUBLISHED
            ? ($this->route('content')?->published_at ?? now())
            : null;

        return [
            'type' => (string) $this->input('type'),
            'slug' => trim((string) $this->input('slug')),
            'default_locale' => (string) $this->input('default_locale'),
            'status' => $status,
            'original_title' => trim((string) $this->input('original_title')),
            'title' => $this->normalizeTranslatableField('title'),
            'tagline' => $this->normalizeTranslatableField('tagline'),
            'short_description' => $this->normalizeTranslatableField('short_description'),
            'description' => $this->normalizeTranslatableField('description'),
            'editor_notes' => $this->normalizeTranslatableField('editor_notes'),
            'meta_title' => $this->normalizeTranslatableField('meta_title'),
            'meta_description' => $this->normalizeTranslatableField('meta_description'),
            'release_year' => $this->filled('release_year') ? (int) $this->input('release_year') : null,
            'country_code' => $this->filled('country_code') ? (string) $this->input('country_code') : null,
            'imdb_rating' => $this->filled('imdb_rating') ? (float) $this->input('imdb_rating') : null,
            'platform_rating' => $this->filled('platform_rating') ? (float) $this->input('platform_rating') : null,
            'runtime_minutes' => $this->filled('runtime_minutes') ? (int) $this->input('runtime_minutes') : null,
            'age_rating' => $this->filled('age_rating') ? (string) $this->input('age_rating') : null,
            'poster_url' => trim((string) $this->input('poster_url')),
            'backdrop_url' => trim((string) $this->input('backdrop_url')),
            'hero_desktop_url' => $this->filled('hero_desktop_url') ? trim((string) $this->input('hero_desktop_url')) : null,
            'hero_mobile_url' => $this->filled('hero_mobile_url') ? trim((string) $this->input('hero_mobile_url')) : null,
            'trailer_url' => $this->filled('trailer_url') ? trim((string) $this->input('trailer_url')) : null,
            'preview_images' => collect($this->input('preview_images', []))
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->values()
                ->all(),
            'cast_members' => $this->normalizeCastMembers(),
            'crew_members' => $this->normalizeCrewMembers(),
            'videos' => $this->normalizeVideos(),
            'seasons' => $this->normalizeSeasons(),
            'subtitle_locales' => collect($this->input('subtitle_locales', []))
                ->map(fn (mixed $value): string => (string) $value)
                ->filter()
                ->values()
                ->all(),
            'available_qualities' => collect($this->input('available_qualities', []))
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'content_formats' => $this->normalizeContentFormats(),
            'rights_windows' => $this->normalizeRightsWindows(),
            'subtitle_tracks' => $this->normalizeSubtitleTracks(),
            'premiere_events' => $this->normalizePremiereEvents(),
            'creator_ids' => collect($this->input('creator_ids', []))
                ->map(fn (mixed $value): int => (int) $value)
                ->unique()
                ->values()
                ->all(),
            'is_featured' => $this->boolean('is_featured', false),
            'is_trending' => $this->boolean('is_trending', false),
            'is_free' => $this->boolean('is_free', false),
            'price_amount' => $this->filled('price_amount') ? (float) $this->input('price_amount') : 0,
            'currency' => strtoupper((string) ($this->input('currency') ?: Content::DEFAULT_CURRENCY)),
            'rental_days' => $this->filled('rental_days') ? (int) $this->input('rental_days') : null,
            'sort_order' => (int) $this->input('sort_order', 0),
            'canonical_url' => $this->filled('canonical_url') ? trim((string) $this->input('canonical_url')) : null,
            'published_at' => $publishedAt,
        ];
    }

    public function taxonomyIds(): array
    {
        return collect($this->input('taxonomy_ids', []))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    protected function localizedRules(string $field, bool $required, int $max): array
    {
        $rules = [
            $field => [$required ? 'required' : 'nullable', 'array'],
        ];

        foreach (Content::supportedLocales() as $locale) {
            $rules["{$field}.{$locale}"] = [$required ? 'required' : 'nullable', 'string', "max:{$max}"];
        }

        return $rules;
    }

    protected function imageAssetRules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:2048',
            function (string $attribute, mixed $value, Closure $fail): void {
                $stringValue = trim((string) $value);

                if ($stringValue === '') {
                    return;
                }

                $scheme = parse_url($stringValue, PHP_URL_SCHEME);
                $isHttpUrl = filter_var($stringValue, FILTER_VALIDATE_URL) !== false
                    && in_array($scheme, ['http', 'https'], true);

                if (! $isHttpUrl) {
                    $fail("The {$attribute} field must be a valid image URL (https://...).");
                }
            },
        ];
    }

    protected function normalizeTranslatableField(string $field): array
    {
        return $this->normalizeTranslatableValue($this->input($field, []));
    }

    protected function normalizeContentFormats(): array
    {
        return collect($this->input('content_formats', []))
            ->map(function (mixed $item, int $index): array {
                return [
                    'id' => data_get($item, 'id'),
                    'quality' => (string) data_get($item, 'quality'),
                    'format_type' => (string) (data_get($item, 'format_type') ?: 'main'),
                    'bunny_library_id' => trim((string) data_get($item, 'bunny_library_id')),
                    'bunny_video_id' => trim((string) data_get($item, 'bunny_video_id')),
                    'stream_url' => $this->filledArrayValue($item, 'stream_url'),
                    'token_path' => $this->filledArrayValue($item, 'token_path'),
                    'drm_policy' => (string) (data_get($item, 'drm_policy') ?: 'tokenized'),
                    'is_active' => filter_var(data_get($item, 'is_active', true), FILTER_VALIDATE_BOOL),
                    'is_default' => filter_var(data_get($item, 'is_default', false), FILTER_VALIDATE_BOOL),
                    'sort_order' => (int) data_get($item, 'sort_order', $index),
                    'meta' => data_get($item, 'meta', []),
                ];
            })
            ->filter(fn (array $item): bool => $item['quality'] !== '' && $item['bunny_library_id'] !== '' && $item['bunny_video_id'] !== '')
            ->values()
            ->all();
    }

    protected function normalizeRightsWindows(): array
    {
        return collect($this->input('rights_windows', []))
            ->map(function (mixed $item): array {
                return [
                    'id' => data_get($item, 'id'),
                    'content_format_quality' => $this->filledArrayValue($item, 'content_format_quality'),
                    'country_code' => $this->filledArrayValue($item, 'country_code'),
                    'is_allowed' => filter_var(data_get($item, 'is_allowed', true), FILTER_VALIDATE_BOOL),
                    'starts_at' => $this->filledArrayValue($item, 'starts_at'),
                    'ends_at' => $this->filledArrayValue($item, 'ends_at'),
                    'meta' => data_get($item, 'meta', []),
                ];
            })
            ->filter(fn (array $item): bool => $item['country_code'] !== null || $item['content_format_quality'] !== null)
            ->values()
            ->all();
    }

    protected function normalizeSubtitleTracks(): array
    {
        return collect($this->input('subtitle_tracks', []))
            ->map(function (mixed $item, int $index): array {
                return [
                    'id' => data_get($item, 'id'),
                    'content_format_quality' => $this->filledArrayValue($item, 'content_format_quality'),
                    'locale' => (string) data_get($item, 'locale'),
                    'label' => trim((string) data_get($item, 'label')),
                    'file_url' => trim((string) data_get($item, 'file_url')),
                    'is_default' => filter_var(data_get($item, 'is_default', false), FILTER_VALIDATE_BOOL),
                    'sort_order' => (int) data_get($item, 'sort_order', $index),
                ];
            })
            ->filter(fn (array $item): bool => $item['locale'] !== '' && $item['label'] !== '' && $item['file_url'] !== '')
            ->values()
            ->all();
    }

    protected function normalizePremiereEvents(): array
    {
        return collect($this->input('premiere_events', []))
            ->map(function (mixed $item): array {
                return [
                    'id' => data_get($item, 'id'),
                    'title' => trim((string) data_get($item, 'title')),
                    'starts_at' => $this->filledArrayValue($item, 'starts_at'),
                    'ends_at' => $this->filledArrayValue($item, 'ends_at'),
                    'is_active' => filter_var(data_get($item, 'is_active', true), FILTER_VALIDATE_BOOL),
                    'is_public' => filter_var(data_get($item, 'is_public', true), FILTER_VALIDATE_BOOL),
                    'meta' => data_get($item, 'meta', []),
                ];
            })
            ->filter(fn (array $item): bool => $item['title'] !== '' && $item['starts_at'] !== null)
            ->values()
            ->all();
    }

    protected function normalizeCastMembers(): array
    {
        return collect($this->input('cast_members', []))
            ->map(function (mixed $member, int $index): array {
                $characterName = $this->normalizeTranslatableValue(
                    data_get($member, 'character_name', data_get($member, 'role')),
                );

                return [
                    'id' => data_get($member, 'id') ?: Str::uuid()->toString(),
                    'name' => trim((string) data_get($member, 'name')),
                    'credit_type' => (string) (data_get($member, 'credit_type') ?: 'lead_actor'),
                    'character_name' => $characterName,
                    'avatar_url' => $this->filledArrayValue($member, 'avatar_url'),
                    'sort_order' => (int) data_get($member, 'sort_order', $index),
                ];
            })
            ->filter(fn (array $member): bool => $member['name'] !== '' && $this->hasTranslatedValue($member['character_name']))
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    protected function normalizeCrewMembers(): array
    {
        return collect($this->input('crew_members', []))
            ->map(function (mixed $member, int $index): array {
                $jobTitle = $this->normalizeTranslatableValue(
                    data_get($member, 'job_title', data_get($member, 'job')),
                );

                return [
                    'id' => data_get($member, 'id') ?: Str::uuid()->toString(),
                    'name' => trim((string) data_get($member, 'name')),
                    'credit_type' => (string) (data_get($member, 'credit_type') ?: 'director'),
                    'job_title' => $jobTitle,
                    'avatar_url' => $this->filledArrayValue($member, 'avatar_url'),
                    'sort_order' => (int) data_get($member, 'sort_order', $index),
                ];
            })
            ->filter(fn (array $member): bool => $member['name'] !== '' && $this->hasTranslatedValue($member['job_title']))
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    protected function normalizeVideos(): array
    {
        $videos = collect($this->input('videos', []))
            ->map(function (mixed $video, int $index): array {
                $title = $this->normalizeTranslatableValue(data_get($video, 'title'));
                $description = $this->normalizeNullableTranslatableValue(data_get($video, 'description'));

                return [
                    'id' => data_get($video, 'id') ?: Str::uuid()->toString(),
                    'type' => (string) data_get($video, 'type'),
                    'title' => $title,
                    'description' => $description,
                    'video_url' => trim((string) data_get($video, 'video_url')),
                    'thumbnail_url' => $this->filledArrayValue($video, 'thumbnail_url'),
                    'duration_seconds' => data_get($video, 'duration_seconds') !== null && data_get($video, 'duration_seconds') !== ''
                        ? (int) data_get($video, 'duration_seconds')
                        : null,
                    'is_primary' => (bool) data_get($video, 'is_primary', false),
                    'sort_order' => (int) data_get($video, 'sort_order', $index),
                ];
            })
            ->filter(fn (array $video): bool => $this->hasTranslatedValue($video['title']) && $video['video_url'] !== '')
            ->sortBy('sort_order')
            ->values();

        if ($videos->isEmpty() && $this->filled('trailer_url')) {
            $videos = collect([[
                'id' => Str::uuid()->toString(),
                'type' => 'trailer',
                'title' => [
                    'ro' => 'Trailer oficial',
                    'ru' => 'Официальный трейлер',
                    'en' => 'Official Trailer',
                ],
                'description' => null,
                'video_url' => trim((string) $this->input('trailer_url')),
                'thumbnail_url' => $this->filled('backdrop_url') ? trim((string) $this->input('backdrop_url')) : null,
                'duration_seconds' => null,
                'is_primary' => true,
                'sort_order' => 0,
            ]]);
        }

        if ($videos->isNotEmpty() && ! $videos->contains(fn (array $video): bool => $video['is_primary'] === true)) {
            $firstId = $videos->first()['id'];
            $videos = $videos->map(fn (array $video): array => [
                ...$video,
                'is_primary' => $video['id'] === $firstId,
            ]);
        }

        return $videos->values()->all();
    }

    protected function normalizeSeasons(): array
    {
        return collect($this->input('seasons', []))
            ->map(function (mixed $season, int $seasonIndex): array {
                $episodes = collect(data_get($season, 'episodes', []))
                    ->map(function (mixed $episode, int $episodeIndex): array {
                        $title = $this->normalizeTranslatableValue(data_get($episode, 'title'));

                        return [
                            'id' => data_get($episode, 'id') ?: Str::uuid()->toString(),
                            'episode_number' => (int) data_get($episode, 'episode_number', $episodeIndex + 1),
                            'title' => $title,
                            'description' => $this->normalizeNullableTranslatableValue(data_get($episode, 'description')),
                            'runtime_minutes' => data_get($episode, 'runtime_minutes') !== null && data_get($episode, 'runtime_minutes') !== ''
                                ? (int) data_get($episode, 'runtime_minutes')
                                : null,
                            'thumbnail_url' => $this->filledArrayValue($episode, 'thumbnail_url'),
                            'backdrop_url' => $this->filledArrayValue($episode, 'backdrop_url'),
                            'video_url' => $this->filledArrayValue($episode, 'video_url'),
                            'trailer_url' => $this->filledArrayValue($episode, 'trailer_url'),
                            'sort_order' => (int) data_get($episode, 'sort_order', $episodeIndex),
                        ];
                    })
                    ->filter(fn (array $episode): bool => $this->hasTranslatedValue($episode['title']))
                    ->sortBy('sort_order')
                    ->values()
                    ->all();

                return [
                    'id' => data_get($season, 'id') ?: Str::uuid()->toString(),
                    'season_number' => (int) data_get($season, 'season_number', $seasonIndex + 1),
                    'title' => $this->normalizeNullableTranslatableValue(data_get($season, 'title')),
                    'description' => $this->normalizeNullableTranslatableValue(data_get($season, 'description')),
                    'poster_url' => $this->filledArrayValue($season, 'poster_url'),
                    'sort_order' => (int) data_get($season, 'sort_order', $seasonIndex),
                    'episodes' => $episodes,
                ];
            })
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    protected function normalizeTranslatableValue(mixed $value): array
    {
        if (is_array($value)) {
            return collect(Content::supportedLocales())
                ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($value[$locale] ?? ''))])
                ->all();
        }

        $stringValue = trim((string) $value);

        return collect(Content::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => $stringValue])
            ->all();
    }

    protected function normalizeNullableTranslatableValue(mixed $value): ?array
    {
        $translations = $this->normalizeTranslatableValue($value);

        return $this->hasTranslatedValue($translations) ? $translations : null;
    }

    protected function hasTranslatedValue(?array $translations): bool
    {
        return collect($translations ?? [])
            ->contains(fn (mixed $value): bool => trim((string) $value) !== '');
    }

    protected function filledArrayValue(mixed $source, string $path): ?string
    {
        $value = trim((string) data_get($source, $path));

        return $value !== '' ? $value : null;
    }
}
