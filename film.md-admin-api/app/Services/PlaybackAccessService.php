<?php

namespace App\Services;

use App\Models\Content;
use App\Models\ContentFormat;
use App\Models\PremiereEvent;
use App\Models\ContentRightsWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PlaybackAccessService
{
    public function resolveAvailableFormat(Content $content, ?string $countryCode, ?string $quality = null): ?ContentFormat
    {
        $formats = $content->relationLoaded('formats')
            ? $content->formats
            : $content->formats()->get();

        $candidateFormats = $formats
            ->filter(fn (ContentFormat $format): bool => $format->is_active)
            ->when($quality !== null, fn (Collection $items) => $items->where('quality', $quality))
            ->sortBy('sort_order')
            ->sortByDesc('is_default')
            ->values();

        foreach ($candidateFormats as $format) {
            if ($this->isAllowedForCountry($content, $format, $countryCode)) {
                return $format;
            }
        }

        return null;
    }

    public function isContentCurrentlyAvailable(Content $content): bool
    {
        if ($content->status !== Content::STATUS_PUBLISHED) {
            return false;
        }

        if ($content->published_at !== null && $content->published_at->isFuture()) {
            return false;
        }

        return true;
    }

    public function isAllowedForCountry(Content $content, ContentFormat $format, ?string $countryCode): bool
    {
        $rights = $content->relationLoaded('rightsWindows')
            ? $content->rightsWindows
            : $content->rightsWindows()->get();
        $now = Carbon::now();

        $matches = $rights
            ->filter(function (ContentRightsWindow $window) use ($countryCode, $format, $now): bool {
                if ($window->content_format_id !== null && $window->content_format_id !== $format->id) {
                    return false;
                }

                if ($window->country_code !== null && $window->country_code !== $countryCode) {
                    return false;
                }

                if ($window->starts_at !== null && $window->starts_at->isFuture()) {
                    return false;
                }

                if ($window->ends_at !== null && $window->ends_at->lt($now)) {
                    return false;
                }

                return true;
            })
            ->values();

        if ($matches->isEmpty()) {
            return true;
        }

        if ($matches->contains(fn (ContentRightsWindow $window): bool => $window->is_allowed === false)) {
            return false;
        }

        return $matches->contains(fn (ContentRightsWindow $window): bool => $window->is_allowed);
    }

    public function nextPublicPremiere(Content $content): ?PremiereEvent
    {
        $events = $content->relationLoaded('premiereEvents')
            ? $content->premiereEvents
            : $content->premiereEvents()->get();

        return $events
            ->filter(fn (PremiereEvent $event): bool => $event->is_active && $event->is_public)
            ->filter(fn (PremiereEvent $event): bool => $event->starts_at !== null && $event->starts_at->isFuture())
            ->sortBy('starts_at')
            ->first();
    }
}
