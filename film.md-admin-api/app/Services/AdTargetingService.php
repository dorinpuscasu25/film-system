<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Content;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Selects the best ad campaign for a given content/country/placement,
 * honoring frequency caps and active windows.
 */
class AdTargetingService
{
    public const PLACEMENTS = [
        AdCampaign::PLACEMENT_PRE_ROLL,
        AdCampaign::PLACEMENT_MID_ROLL,
        AdCampaign::PLACEMENT_POST_ROLL,
    ];

    /**
     * @return Collection<int, AdCampaign>
     */
    public function eligibleCampaigns(
        string $placement,
        Content $content,
        ?string $countryCode = null,
        ?string $allowedGroup = null,
    ): Collection {
        $now = Carbon::now();

        $campaigns = AdCampaign::query()
            ->with(['creatives' => fn ($q) => $q->where('is_active', true)])
            ->where('is_active', true)
            ->where('status', AdCampaign::STATUS_ACTIVE)
            ->where('placement', $placement)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get();

        return $campaigns->filter(function (AdCampaign $campaign) use ($content, $countryCode, $allowedGroup): bool {
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
    public function pickForSession(
        string $placement,
        Content $content,
        ?string $countryCode,
        ?string $allowedGroup,
        ?string $playbackSessionId,
        ?int $userId,
    ): ?AdCampaign {
        $candidates = $this->eligibleCampaigns($placement, $content, $countryCode, $allowedGroup)
            ->sortByDesc('bid_amount')
            ->values();

        foreach ($candidates as $campaign) {
            if (! $this->frequencyCapAllows($campaign, $playbackSessionId, $userId)) {
                continue;
            }

            return $campaign;
        }

        return null;
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
