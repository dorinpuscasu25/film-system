<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['content_creator_id', 'content_id', 'share_percent', 'territories', 'effective_from', 'effective_until', 'contract_reference', 'status', 'notes', 'created_by'])]
class CreatorContractVersion extends Model
{
    public function creator(): BelongsTo { return $this->belongsTo(ContentCreator::class, 'content_creator_id'); }
    public function content(): BelongsTo { return $this->belongsTo(Content::class); }

    protected function casts(): array
    {
        return ['share_percent' => 'float', 'territories' => 'array', 'effective_from' => 'date', 'effective_until' => 'date'];
    }
}
