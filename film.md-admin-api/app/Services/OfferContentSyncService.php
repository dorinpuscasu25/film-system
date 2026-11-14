<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Offer;

/**
 * Keeps the per-title fields derived from offers (is_free, available_qualities) in step after
 * offers are created, changed or removed.
 */
class OfferContentSyncService
{
    public function sync(?Content $content): void
    {
        if ($content === null) {
            return;
        }

        $formatQualities = $content->formats()
            ->where('format_type', 'main')
            ->where('is_active', true)
            ->pluck('quality');
        $offerQualities = $content->offers()
            ->where('is_active', true)
            ->pluck('quality');

        $content->forceFill([
            'is_free' => $content->offers()->where('offer_type', Offer::TYPE_FREE)->exists(),
            'available_qualities' => $formatQualities
                ->merge($offerQualities)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ])->save();
    }
}
