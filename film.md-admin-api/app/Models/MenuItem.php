<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

#[Fillable([
    'menu_id',
    'parent_id',
    'title',
    'type',
    'cms_page_id',
    'content_id',
    'url',
    'target',
    'active',
    'nestable',
    'sort_order',
    'depth',
])]
class MenuItem extends Model
{
    use HasTranslations;

    public const MAX_DEPTH = 3;

    public const TYPE_PAGE = 'page';
    public const TYPE_CONTENT = 'content';
    public const TYPE_CUSTOM = 'custom';

    public array $translatable = ['title'];

    public static function types(): array
    {
        return [
            self::TYPE_PAGE => 'Pagina',
            self::TYPE_CONTENT => 'Filme',
            self::TYPE_CUSTOM => 'Custom URL',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'nestable' => 'boolean',
            'sort_order' => 'integer',
            'depth' => 'integer',
        ];
    }
}
