<?php

namespace App\Http\Requests\Admin;

use App\Models\Content;
use App\Models\HomePageSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateHomePageSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'sections' => ['required', 'array'],
            'sections.*.id' => ['nullable', 'integer', Rule::exists('home_page_sections', 'id')],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.section_type' => ['required', Rule::in(HomePageSection::availableTypes())],
            'sections.*.active' => ['sometimes', 'boolean'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.source_mode' => ['nullable', Rule::in(HomePageSection::availableSourceModes())],
            'sections.*.limit' => ['nullable', 'integer', 'between:1,40'],
            'sections.*.content_ids' => ['nullable', 'array'],
            'sections.*.content_ids.*' => ['integer', Rule::exists('contents', 'id')],
            'sections.*.rule_filters' => ['nullable', 'array'],
            'sections.*.rule_filters.taxonomy_ids' => ['nullable', 'array'],
            'sections.*.rule_filters.taxonomy_ids.*' => ['integer', Rule::exists('taxonomies', 'id')],
            'sections.*.rule_filters.content_types' => ['nullable', 'array'],
            'sections.*.rule_filters.content_types.*' => [Rule::in(Content::availableTypes())],
            'sections.*.rule_filters.access' => ['nullable', Rule::in(HomePageSection::availableAccessModes())],
            'sections.*.rule_filters.sort_mode' => ['nullable', Rule::in(HomePageSection::availableSortModes())],
            'sections.*.rule_filters.matching_strategy' => ['nullable', Rule::in(HomePageSection::availableMatchStrategies())],
            'sections.*.rule_filters.featured_only' => ['nullable', 'boolean'],
            'sections.*.rule_filters.trending_only' => ['nullable', 'boolean'],
            'sections.*.hero_slides' => ['nullable', 'array'],
            'sections.*.hero_slides.*.id' => ['nullable', 'string', 'max:64'],
            'sections.*.hero_slides.*.content_id' => ['required', 'integer', Rule::exists('contents', 'id')],
            'sections.*.hero_slides.*.active' => ['sometimes', 'boolean'],
            'sections.*.hero_slides.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.hero_slides.*.desktop_image_url' => $this->imageAssetRules(false),
            'sections.*.hero_slides.*.mobile_image_url' => $this->imageAssetRules(false),
            ...$this->localizedRules('sections.*.title', false, 255),
            ...$this->localizedRules('sections.*.subtitle', false, 500),
            ...$this->localizedRules('sections.*.hero_slides.*.eyebrow', false, 120),
            ...$this->localizedRules('sections.*.hero_slides.*.title', false, 255),
            ...$this->localizedRules('sections.*.hero_slides.*.description', false, 2000),
            ...$this->localizedRules('sections.*.hero_slides.*.primary_cta_label', false, 120),
            ...$this->localizedRules('sections.*.hero_slides.*.secondary_cta_label', false, 120),
        ];
    }

    public function normalizedSections(): array
    {
        return collect($this->input('sections', []))
            ->map(function (mixed $section, int $index): array {
                $sectionType = (string) data_get($section, 'section_type', HomePageSection::TYPE_CONTENT_CAROUSEL);

                return [
                    'id' => data_get($section, 'id'),
                    'name' => trim((string) data_get($section, 'name', '')),
                    'section_type' => $sectionType,
                    'active' => (bool) data_get($section, 'active', true),
                    'sort_order' => (int) data_get($section, 'sort_order', $index),
                    'title' => $this->normalizeTranslatableValue(data_get($section, 'title')),
                    'subtitle' => $this->normalizeTranslatableValue(data_get($section, 'subtitle')),
                    'source_mode' => $sectionType === HomePageSection::TYPE_CONTENT_CAROUSEL
                        ? (string) data_get($section, 'source_mode', HomePageSection::SOURCE_DYNAMIC)
                        : null,
                    'limit' => $sectionType === HomePageSection::TYPE_CONTENT_CAROUSEL
                        ? max(1, (int) data_get($section, 'limit', 12))
                        : null,
                    'content_ids' => $sectionType === HomePageSection::TYPE_CONTENT_CAROUSEL
                        ? collect(data_get($section, 'content_ids', []))
                            ->map(fn ($value): int => (int) $value)
                            ->filter()
                            ->unique()
                            ->values()
                            ->all()
                        : [],
                    'rule_filters' => $sectionType === HomePageSection::TYPE_CONTENT_CAROUSEL
                        ? $this->normalizeRuleFilters(data_get($section, 'rule_filters', []))
                        : null,
                    'hero_slides' => $sectionType === HomePageSection::TYPE_HERO_SLIDER
                        ? $this->normalizeHeroSlides(data_get($section, 'hero_slides', []))
                        : [],
                    'meta' => [],
                ];
            })
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
            function (string $attribute, mixed $value, \Closure $fail): void {
                $stringValue = trim((string) $value);

                if ($stringValue === '') {
                    return;
                }

                $scheme = parse_url($stringValue, PHP_URL_SCHEME);
                $isHttpUrl = filter_var($stringValue, FILTER_VALIDATE_URL) !== false
                    && in_array($scheme, ['http', 'https'], true);
                $isDataImage = preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9\/+=]+$/', $stringValue) === 1;

                if (! $isHttpUrl && ! $isDataImage) {
                    $fail("The {$attribute} field must be an image URL or an uploaded image preview.");
                }
            },
        ];
    }

    protected function normalizeRuleFilters(mixed $filters): array
    {
        return [
            'taxonomy_ids' => collect(data_get($filters, 'taxonomy_ids', []))
                ->map(fn ($value): int => (int) $value)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'content_types' => collect(data_get($filters, 'content_types', []))
                ->map(fn ($value): string => (string) $value)
                ->filter(fn ($value): bool => in_array($value, Content::availableTypes(), true))
                ->unique()
                ->values()
                ->all(),
            'access' => (string) data_get($filters, 'access', HomePageSection::ACCESS_ALL),
            'sort_mode' => (string) data_get($filters, 'sort_mode', HomePageSection::SORT_RELEASE_YEAR_DESC),
            'matching_strategy' => (string) data_get($filters, 'matching_strategy', HomePageSection::MATCH_ANY),
            'featured_only' => (bool) data_get($filters, 'featured_only', false),
            'trending_only' => (bool) data_get($filters, 'trending_only', false),
        ];
    }

    protected function normalizeHeroSlides(mixed $slides): array
    {
        return collect($slides ?? [])
            ->map(function (mixed $slide, int $index): array {
                return [
                    'id' => (string) (data_get($slide, 'id') ?: Str::uuid()),
                    'content_id' => (int) data_get($slide, 'content_id'),
                    'active' => (bool) data_get($slide, 'active', true),
                    'sort_order' => (int) data_get($slide, 'sort_order', $index),
                    'desktop_image_url' => $this->filledString(data_get($slide, 'desktop_image_url')),
                    'mobile_image_url' => $this->filledString(data_get($slide, 'mobile_image_url')),
                    'eyebrow' => $this->normalizeTranslatableValue(data_get($slide, 'eyebrow')),
                    'title' => $this->normalizeTranslatableValue(data_get($slide, 'title')),
                    'description' => $this->normalizeTranslatableValue(data_get($slide, 'description')),
                    'primary_cta_label' => $this->normalizeTranslatableValue(data_get($slide, 'primary_cta_label')),
                    'secondary_cta_label' => $this->normalizeTranslatableValue(data_get($slide, 'secondary_cta_label')),
                ];
            })
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    protected function normalizeTranslatableValue(mixed $value): array
    {
        if (! is_array($value)) {
            return collect(Content::supportedLocales())
                ->mapWithKeys(fn (string $locale) => [$locale => trim((string) $value)])
                ->all();
        }

        return collect(Content::supportedLocales())
            ->mapWithKeys(fn (string $locale) => [$locale => trim((string) ($value[$locale] ?? ''))])
            ->all();
    }

    protected function filledString(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
