<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Selects the best ad campaign for a given content/country/placement,
 * honoring frequency caps and active windows.
 */
class AdTargetingService
{
    /** Maturity ratings ordered from most permissive to most restrictive. */
    public const AGE_RATINGS_ORDERED = ['AG', 'A.P.-12', 'N-15', 'I.M.-18', 'I.M.-18-XXX', 'I.C.'];

    public const PLATFORMS = ['web', 'ios', 'tvos', 'android'];

    public const PLACEMENTS = [
        AdCampaign::PLACEMENT_PRE_ROLL,
        AdCampaign::PLACEMENT_MID_ROLL,
        AdCampaign::PLACEMENT_POST_ROLL,
    ];

    /**
     * @return Collection<int, AdCampaign>
     */
    public function eligibleCampaigns(string $placement, AdRequestContext $context): Collection
    {
        $content = $context->content;
        $countryCode = $context->countryCode;
        $allowedGroup = $context->allowedGroup;
        $now = Carbon::now();

        $campaigns = AdCampaign::query()
            ->with(['creatives' => fn ($q) => $q->where('is_active', true)])
            ->where('is_active', true)
            ->where('status', AdCampaign::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get()
            // Filtered in PHP because `placements` is JSON and the legacy single
            // `placement` column is still the fallback for older rows.
            ->filter(fn (AdCampaign $campaign): bool => in_array($placement, $campaign->resolvedPlacements(), true));

        return $campaigns->filter(function (AdCampaign $campaign) use ($content, $countryCode, $allowedGroup, $context): bool {
            // Kids profiles get no advertising at all unless a campaign opts in.
            if ($context->isKidsProfile && ($campaign->exclude_kids_profiles ?? true)) {
                return false;
            }

            // Platform filter (web / ios / tvos / android).
            $platforms = array_map('strtolower', $campaign->target_platforms ?? []);
            if (! empty($platforms) && $context->platform !== null
                && ! in_array(strtolower($context->platform), $platforms, true)) {
                return false;
            }

            // Content maturity filter: which ratings may carry this creative.
            $ageRatings = array_map('strtoupper', $campaign->target_age_ratings ?? []);
            if (! empty($ageRatings)) {
                $contentRating = strtoupper((string) ($content->age_rating ?? 'AG'));
                if (! in_array($contentRating, $ageRatings, true)) {
                    return false;
                }
            }

            // Viewer maturity filter: an 18+ creative must not reach a profile
            // capped below that rating.
            $minProfileRating = $campaign->target_min_profile_rating;
            if ($minProfileRating !== null && $context->profileMaxRating !== null) {
                if (self::ratingIndex($context->profileMaxRating) < self::ratingIndex($minProfileRating)) {
                    return false;
                }
            }

            // Per-content include/exclude
            $included = $campaign->target_content_ids ?? [];
            if (! empty($included) && ! in_array($content->id, array_map('intval', $included), true)) {
                return false;
            }
            $excluded = $campaign->target_excluded_content_ids ?? [];
            if (! empty($excluded) && in_array($content->id, array_map('intval', $excluded), true)) {
                return false;
            }

            // Country filter
            $countries = array_map('strtoupper', $campaign->target_countries ?? []);
            if (! empty($countries) && $countries !== ['ALL']) {
                if ($countryCode === null || ! in_array(strtoupper($countryCode), $countries, true)) {
                    return false;
                }
            }

            // Group filter (e.g., "trailers", "movies", "premium")
            $groups = $campaign->target_groups ?? [];
            if (! empty($groups) && $allowedGroup !== null && ! in_array($allowedGroup, $groups, true)) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * Picks the highest-bid campaign that hasn't exceeded frequency caps for
     * the given session. Returns null if no eligible campaign remains.
     */
    public function pickForSession(string $placement, AdRequestContext $context): ?AdCampaign
    {
        $candidates = $this->eligibleCampaigns($placement, $context)
            ->sortByDesc('bid_amount')
            ->values();

        foreach ($candidates as $campaign) {
            if (! $this->frequencyCapAllows($campaign, $context->playbackSessionId, $context->userId)) {
                continue;
            }

            return $campaign;
        }

        return null;
    }

    /**
     * Position of a maturity rating in {@see self::AGE_RATINGS_ORDERED}.
     * Unknown ratings fall back to the most permissive bucket.
     */
    public static function ratingIndex(string $rating): int
    {
        $index = array_search(strtoupper(trim($rating)), self::AGE_RATINGS_ORDERED, true);

        return is_int($index) ? $index : 0;
    }

    public function recordImpression(AdCampaign $campaign, ?string $playbackSessionId, ?int $userId): void
    {
        if ($playbackSessionId !== null && $campaign->frequency_cap_per_session) {
            Redis::incr($this->sessionKey($campaign->id, $playbackSessionId));
            Redis::expire($this->sessionKey($campaign->id, $playbackSessionId), 7200);
        }
        if ($userId !== null && $campaign->frequency_cap_per_day) {
            $key = $this->dailyKey($campaign->id, $userId);
            Redis::incr($key);
            Redis::expireat($key, Carbon::tomorrow()->timestamp);
        }
    }

    private function frequencyCapAllows(AdCampaign $campaign, ?string $playbackSessionId, ?int $userId): bool
    {
        if ($campaign->frequency_cap_per_session && $playbackSessionId !== null) {
            $count = (int) (Redis::get($this->sessionKey($campaign->id, $playbackSessionId)) ?? 0);
            if ($count >= (int) $campaign->frequency_cap_per_session) {
                return false;
            }
        }
        if ($campaign->frequency_cap_per_day && $userId !== null) {
            $count = (int) (Redis::get($this->dailyKey($campaign->id, $userId)) ?? 0);
            if ($count >= (int) $campaign->frequency_cap_per_day) {
                return false;
            }
        }

        return true;
    }

    private function sessionKey(int $campaignId, string $sessionId): string
    {
        return "ad:freq:session:{$campaignId}:{$sessionId}";
    }

    private function dailyKey(int $campaignId, int $userId): string
    {
        return 'ad:freq:daily:'.Carbon::today()->toDateString().":{$campaignId}:{$userId}";
    }
}
