<?php

namespace App\Services;

use App\Models\Content;
use App\Models\ContentFormat;
use App\Models\ContentRightsWindow;
use App\Models\Offer;
use App\Models\PremiereEvent;
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
            ->filter(
                fn (ContentFormat $format): bool => $format->is_active
                    && $format->format_type === ContentFormat::TYPE_MAIN,
            )
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

    public function hasActiveMainFormat(Content $content): bool
    {
        $formats = $content->relationLoaded('formats')
            ? $content->formats
            : $content->formats()->get();

        return $formats->contains(
            fn (ContentFormat $format): bool => $format->is_active
                && $format->format_type === ContentFormat::TYPE_MAIN,
        );
    }

    public function hasConfiguredPlaybackSource(Content $content): bool
    {
        return $this->hasActiveMainFormat($content)
            || $this->hasManualOfferPlaybackSource($content)
            || $this->hasSeriesEpisodePlaybackSource($content);
    }

    public function hasAvailablePlaybackSource(Content $content, ?string $countryCode): bool
    {
        if ($this->resolveAvailableFormat($content, $countryCode) !== null) {
            return true;
        }

        if (! $this->isContentAllowedForCountry($content, $countryCode)) {
            return false;
        }

        return $this->hasManualOfferPlaybackSource($content)
            || $this->hasSeriesEpisodePlaybackSource($content);
    }

    public function hasManualOfferPlaybackSource(Content $content): bool
    {
        $offers = $content->relationLoaded('offers')
            ? $content->offers
            : $content->offers()->get();

        return $offers->contains(
            fn (Offer $offer): bool => $offer->is_active
                && trim((string) $offer->playback_url) !== '',
        );
    }

    public function hasSeriesEpisodePlaybackSource(Content $content): bool
    {
        if ($content->type !== Content::TYPE_SERIES) {
            return false;
        }

        foreach ($content->seasons ?? [] as $season) {
            foreach ((array) data_get($season, 'episodes', []) as $episode) {
                $libraryId = trim((string) data_get($episode, 'bunny_library_id', ''));
                $videoId = trim((string) data_get($episode, 'bunny_video_id', ''));
                $videoUrl = trim((string) data_get($episode, 'video_url', ''));

                if (($libraryId !== '' && $videoId !== '') || $videoUrl !== '') {
                    return true;
                }
            }
        }

        return false;
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

        $relevantRights = $rights
            ->filter(
                fn (ContentRightsWindow $window): bool => $window->content_format_id === null
                    || $window->content_format_id === $format->id,
            )
            ->values();

        return $this->evaluateRightsWindows($relevantRights, $countryCode);
    }

    public function isContentAllowedForCountry(Content $content, ?string $countryCode): bool
    {
        $rights = $content->relationLoaded('rightsWindows')
            ? $content->rightsWindows
            : $content->rightsWindows()->get();

        return $this->evaluateRightsWindows(
            $rights
                ->filter(fn (ContentRightsWindow $window): bool => $window->content_format_id === null)
                ->values(),
            $countryCode,
        );
    }

    /**
     * @param  Collection<int, ContentRightsWindow>  $rights
     */
    protected function evaluateRightsWindows(Collection $rights, ?string $countryCode): bool
    {
        if ($rights->isEmpty()) {
            return true;
        }

        $now = Carbon::now();
        $activeMatches = $rights
            ->filter(function (ContentRightsWindow $window) use ($countryCode, $now): bool {
                if ($window->country_code !== null && $window->country_code !== $countryCode) {
                    return false;
                }

                if ($window->starts_at !== null && $window->starts_at->gt($now)) {
                    return false;
                }

                return $window->ends_at === null || $window->ends_at->gte($now);
            })
            ->values();

        if ($activeMatches->contains(
            fn (ContentRightsWindow $window): bool => $window->is_allowed === false,
        )) {
            return false;
        }

        $hasAllowList = $rights->contains(
            fn (ContentRightsWindow $window): bool => $window->is_allowed === true,
        );

        if (! $hasAllowList) {
            return true;
        }

        return $activeMatches->contains(
            fn (ContentRightsWindow $window): bool => $window->is_allowed === true,
        );
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
