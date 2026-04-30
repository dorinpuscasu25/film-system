<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'content_id',
    'user_id',
    'account_profile_id',
    'rating',
    'comment',
    'locale',
    'status',
])]
class ContentReview extends Model
{
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AccountProfile::class, 'account_profile_id');
    }

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
