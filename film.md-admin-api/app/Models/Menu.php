<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'name',
    'slug',
    'location',
    'description',
    'active',
])]
class Menu extends Model
{
    use HasTranslations;

    public const LOCATION_HEADER = 'header';
    public const LOCATION_FOOTER = 'footer';

    public array $translatable = ['name', 'description'];

    public static function locations(): array
    {
        return [
            self::LOCATION_HEADER => 'Header',
            self::LOCATION_FOOTER => 'Footer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Menu $menu): void {
            if (! $menu->slug) {
                $name = $menu->getTranslation('name', Taxonomy::LOCALE_RO, false)
                    ?: $menu->getTranslation('name', Taxonomy::LOCALE_EN, false)
                    ?: 'menu';
                $menu->slug = Str::slug($name);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
