<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'type',
    'slug',
    'name',
    'description',
    'active',
    'color',
    'content_count',
    'sort_order',
    'meta',
])]
class Taxonomy extends Model
{
    use HasTranslations;

    public const TYPE_GENRE = 'genre';
    public const TYPE_COLLECTION = 'collection';
    public const TYPE_TAG = 'tag';
    public const TYPE_BADGE = 'badge';

    public const LOCALE_RO = 'ro';
    public const LOCALE_RU = 'ru';
    public const LOCALE_EN = 'en';

    public array $translatable = ['name', 'description'];

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_taxonomy')->withTimestamps();
    }

    public static function supportedLocales(): array
    {
        return [self::LOCALE_RO, self::LOCALE_RU, self::LOCALE_EN];
    }

    public static function availableTypes(): array
    {
        return [
            self::TYPE_GENRE,
            self::TYPE_COLLECTION,
            self::TYPE_TAG,
            self::TYPE_BADGE,
        ];
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_GENRE => 'Genres',
            self::TYPE_COLLECTION => 'Collections',
            self::TYPE_TAG => 'Tags',
            self::TYPE_BADGE => 'Badges',
        ];
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'content_count' => 'integer',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }
}
