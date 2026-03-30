<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'name',
    'section_type',
    'active',
    'sort_order',
    'title',
    'subtitle',
    'source_mode',
    'limit',
    'content_ids',
    'rule_filters',
    'hero_slides',
    'meta',
])]
class HomePageSection extends Model
{
    use HasTranslations;

    public const TYPE_HERO_SLIDER = 'hero_slider';
    public const TYPE_CONTENT_CAROUSEL = 'content_carousel';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DYNAMIC = 'dynamic';

    public const MATCH_ANY = 'any';
    public const MATCH_ALL = 'all';

    public const ACCESS_ALL = 'all';
    public const ACCESS_FREE = 'free';
    public const ACCESS_PAID = 'paid';

    public const SORT_MANUAL = 'manual';
    public const SORT_RELEASE_YEAR_DESC = 'release_year_desc';
    public const SORT_RELEASE_YEAR_ASC = 'release_year_asc';
    public const SORT_PUBLISHED_DESC = 'published_desc';
    public const SORT_IMDB_DESC = 'imdb_desc';
    public const SORT_PLATFORM_DESC = 'platform_desc';
    public const SORT_TITLE_ASC = 'title_asc';

    public array $translatable = [
        'title',
        'subtitle',
    ];

    public static function availableTypes(): array
    {
        return [
            self::TYPE_HERO_SLIDER,
            self::TYPE_CONTENT_CAROUSEL,
        ];
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_HERO_SLIDER => 'Hero slider',
            self::TYPE_CONTENT_CAROUSEL => 'Content carousel',
        ];
    }

    public static function availableSourceModes(): array
    {
        return [
            self::SOURCE_MANUAL,
            self::SOURCE_DYNAMIC,
        ];
    }

    public static function sourceModeLabels(): array
    {
        return [
            self::SOURCE_MANUAL => 'Manual selection',
            self::SOURCE_DYNAMIC => 'Dynamic rules',
        ];
    }

    public static function availableSortModes(): array
    {
        return [
            self::SORT_MANUAL,
            self::SORT_RELEASE_YEAR_DESC,
            self::SORT_RELEASE_YEAR_ASC,
            self::SORT_PUBLISHED_DESC,
            self::SORT_IMDB_DESC,
            self::SORT_PLATFORM_DESC,
            self::SORT_TITLE_ASC,
        ];
    }

    public static function sortModeLabels(): array
    {
        return [
            self::SORT_MANUAL => 'Manual order',
            self::SORT_RELEASE_YEAR_DESC => 'Release year: newest first',
            self::SORT_RELEASE_YEAR_ASC => 'Release year: oldest first',
            self::SORT_PUBLISHED_DESC => 'Recently published',
            self::SORT_IMDB_DESC => 'IMDb rating',
            self::SORT_PLATFORM_DESC => 'Platform rating',
            self::SORT_TITLE_ASC => 'Title A-Z',
        ];
    }

    public static function availableAccessModes(): array
    {
        return [
            self::ACCESS_ALL,
            self::ACCESS_FREE,
            self::ACCESS_PAID,
        ];
    }

    public static function accessModeLabels(): array
    {
        return [
            self::ACCESS_ALL => 'All titles',
            self::ACCESS_FREE => 'Free only',
            self::ACCESS_PAID => 'Paid only',
        ];
    }

    public static function availableMatchStrategies(): array
    {
        return [
            self::MATCH_ANY,
            self::MATCH_ALL,
        ];
    }

    public static function matchStrategyLabels(): array
    {
        return [
            self::MATCH_ANY => 'Match any selected taxonomy',
            self::MATCH_ALL => 'Match all selected taxonomies',
        ];
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
            'limit' => 'integer',
            'content_ids' => 'array',
            'rule_filters' => 'array',
            'hero_slides' => 'array',
            'meta' => 'array',
        ];
    }
}
