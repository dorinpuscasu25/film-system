<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'title',
    'slug',
    'status',
    'excerpt',
    'content',
    'meta_title',
    'meta_description',
    'meta_keywords',
    'canonical_url',
    'published_at',
])]
class CmsPage extends Model
{
    use HasTranslations;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';

    public array $translatable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    public static function availableStatuses(): array
    {
        return [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED];
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function normalizeSlugs(): void
    {
        $titles = $this->getTranslations('title');
        $slugs = $this->getTranslations('slug');
        $fallbackTitle = collect($titles)->filter()->first() ?: 'page';
        $normalized = [];

        foreach (Taxonomy::supportedLocales() as $locale) {
            $source = $slugs[$locale] ?? $titles[$locale] ?? $fallbackTitle;
            $source = trim((string) $source, " \t\n\r\0\x0B/");
            $normalized[$locale] = Str::slug($source) ?: 'page';
        }

        $this->setTranslations('slug', $normalized);
    }

    protected static function booted(): void
    {
        static::saving(function (CmsPage $page): void {
            $page->normalizeSlugs();
            if ($page->status === self::STATUS_PUBLISHED && $page->published_at === null) {
                $page->published_at = now();
            }
            if ($page->status === self::STATUS_UNPUBLISHED) {
                $page->published_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }
}
