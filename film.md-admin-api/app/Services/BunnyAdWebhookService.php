<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Content;

/**
 * Constructs the payload Bunny.net Stream calls back to when a video starts.
 * Bunny supports a "Webhook URL" per Stream library that is invoked on
 * playback events (videoPlay, videoFinish, etc.) — see:
 * https://docs.bunny.net/api-reference/core
 *
 * Our webhook returns:
 *   pre_roll  – ad to play before the video
 *   mid_roll  – ad to play at mid_roll_offset_seconds
 *   post_roll – ad to play after the video
 * each shaped as { vast_url, media_url, click_through, skip_offset, campaign_id }
 *
 * Bunny then injects these via its native VMAP/VAST support.
 */
class BunnyAdWebhookService
{
    public function __construct(
        protected AdTargetingService $targeting,
        protected VastService $vast,
    ) {}

    /**
     * @return array<string, array<string, mixed>|null>
     */
    public function buildAdPayload(
        Content $content,
        ?string $countryCode,
        ?string $allowedGroup,
        ?string $playbackSessionId,
        ?int $userId,
    ): array {
        $payload = [];
        foreach (AdTargetingService::PLACEMENTS as $placement) {
            $campaign = $this->targeting->pickForSession(
                $placement,
                $content,
                $countryCode,
                $allowedGroup,
                $playbackSessionId,
                $userId,
            );
            if ($campaign === null) {
                $payload[$this->payloadKey($placement)] = null;
                continue;
            }

            $this->targeting->recordImpression($campaign, $playbackSessionId, $userId);

            $payload[$this->payloadKey($placement)] = [
                'campaign_id' => $campaign->id,
                'placement' => $campaign->placement,
                'mid_roll_offset_seconds' => $campaign->mid_roll_offset_seconds,
                'vast_url' => route('ads.vast', [
                    'campaign' => $campaign->id,
                    'session' => $playbackSessionId,
                    'content' => $content->id,
                ]),
                'media_url' => $campaign->creatives->first()?->media_url,
                'click_through' => $campaign->click_through_url,
                'skip_offset_seconds' => $campaign->skip_offset_seconds,
                'duration_seconds' => $campaign->creatives->first()?->duration_seconds,
            ];
        }

        return $payload;
    }

    private function payloadKey(string $placement): string
    {
        return match ($placement) {
            AdCampaign::PLACEMENT_PRE_ROLL => 'pre_roll',
            AdCampaign::PLACEMENT_MID_ROLL => 'mid_roll',
            AdCampaign::PLACEMENT_POST_ROLL => 'post_roll',
            default => $placement,
        };
    }
}
