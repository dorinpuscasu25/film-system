<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_review_id', 'reporter_id', 'reason', 'details', 'resolved_at'])]
class ContentReviewReport extends Model
{
    public const REASONS = ['offensive', 'spam', 'spoiler', 'other'];

    /** Distinct reports after which a review is hidden until a moderator looks at it. */
    public const AUTO_HIDE_THRESHOLD = 3;

    public function review(): BelongsTo
    {
        return $this->belongsTo(ContentReview::class, 'content_review_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
