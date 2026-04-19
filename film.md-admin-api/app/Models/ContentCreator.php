<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'email',
    'company_name',
    'platform_fee_percent',
    'is_active',
    'meta',
])]
class ContentCreator extends Model
{
    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'content_creator_assignments')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps();
    }

    public function monthlyStatements(): HasMany
    {
        return $this->hasMany(CreatorMonthlyStatement::class);
    }

    protected function casts(): array
    {
        return [
            'platform_fee_percent' => 'float',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }
}
