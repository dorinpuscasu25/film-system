<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdCampaign;

/**
 * Builds a VMAP 1.0 playlist describing every ad break for one playback.
 *
 * A single VAST tag can only describe one break, so pre-roll + mid-roll +
 * post-roll needs VMAP wrapping several VAST documents. The VAST is embedded
 * inline (`VASTAdData`) rather than referenced by URL, which saves a round trip
 * per break and keeps campaign selection — including frequency caps — on one
 * request, so the same campaign cannot be picked twice for one view.
 */
class VmapService
{
    /** Upper bound on materialised repeating mid-rolls for one playback. */
    private const MAX_MID_ROLL_REPEATS = 8;


    public function __construct(
        protected AdTargetingService $targeting,
        protected VastService $vast,
    ) {}

    /**
     * @return array{xml: string, breaks: array<int, array{placement: string, campaign_id: int, time_offset: string}>}
     */
    public function build(AdRequestContext $context, string $trackingBaseUrl): array
    {
        $breaks = [];
        $usedCampaignIds = [];

        foreach (AdTargetingService::PLACEMENTS as $placement) {
            $campaign = $this->targeting->pickForSession($placement, $context);

            // Never repeat one advertiser across breaks in the same view.
            if ($campaign === null || in_array($campaign->id, $usedCampaignIds, true)) {
                continue;
            }

            $usedCampaignIds[] = $campaign->id;
            $this->targeting->recordImpression($campaign, $context->playbackSessionId, $context->userId);

            $breaks[] = [
                'placement' => $placement,
                'campaign_id' => $campaign->id,
                'time_offset' => $this->timeOffset($placement, $campaign),
                'repeat_every_minutes' => $placement === AdCampaign::PLACEMENT_MID_ROLL
                    ? $campaign->mid_roll_every_minutes
                    : null,
                'vast' => $this->vast->buildVastXml(
                    $campaign,
                    $trackingBaseUrl,
                    $context->playbackSessionId !== null ? (int) $context->playbackSessionId : null,
                    [
                        'content_id' => $context->content->id,
                        'placement' => $placement,
                        'platform' => $context->platform,
                        'country_code' => $context->countryCode,
                    ],
                ),
            ];
        }

        return [
            'xml' => $this->renderVmap($breaks),
            'breaks' => array_map(
                fn (array $break): array => [
                    'placement' => $break['placement'],
                    'campaign_id' => $break['campaign_id'],
                    'time_offset' => $break['time_offset'],
                    'repeat_every_minutes' => $break['repeat_every_minutes'] ?? null,
                ],
                $breaks,
            ),
        ];
    }

    /**
     * VMAP offsets: `start`, `end`, or `HH:MM:SS` for a mid-roll.
     */
    private function timeOffset(string $placement, AdCampaign $campaign): string
    {
        return match ($placement) {
            AdCampaign::PLACEMENT_PRE_ROLL => 'start',
            AdCampaign::PLACEMENT_POST_ROLL => 'end',
            default => gmdate('H:i:s', max(0, (int) ($campaign->mid_roll_offset_seconds ?? 0))),
        };
    }

    /**
     * Turns a repeating mid-roll into concrete breaks.
     *
     * VMAP has no "every N minutes" concept, so the server materialises the
     * offsets. Capped so a long film cannot produce an unreasonable number of
     * interruptions.
     *
     * @param array<int, array<string, mixed>> $breaks
     * @return array<int, array<string, mixed>>
     */
    private function expandRepeats(array $breaks): array
    {
        $expanded = [];

        foreach ($breaks as $break) {
            $expanded[] = $break;

            $interval = (int) ($break['repeat_every_minutes'] ?? 0);
            if ($break['placement'] !== AdCampaign::PLACEMENT_MID_ROLL || $interval <= 0) {
                continue;
            }

            $first = $this->offsetToSeconds((string) $break['time_offset']);
            for ($i = 1; $i <= self::MAX_MID_ROLL_REPEATS; $i++) {
                $expanded[] = [
                    ...$break,
                    'time_offset' => gmdate('H:i:s', $first + ($interval * 60 * $i)),
                ];
            }
        }

        return $expanded;
    }

    private function offsetToSeconds(string $offset): int
    {
        $parts = array_map('intval', explode(':', $offset));

        return count($parts) === 3 ? ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2] : 0;
    }

    /**
     * @param array<int, array{placement: string, campaign_id: int, time_offset: string, vast: string}> $breaks
     */
    private function renderVmap(array $breaks): string
    {
        if ($breaks === []) {
            return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<vmap:VMAP xmlns:vmap="http://www.iab.net/videosuite/vmap" version="1.0"/>
XML;
        }

        $rendered = '';
        $breaks = $this->expandRepeats($breaks);
        foreach ($breaks as $index => $break) {
            // Strip the inner XML declaration — only the VMAP document may carry one.
            $vast = preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $break['vast']) ?? $break['vast'];
            $breakId = htmlspecialchars($break['placement'], ENT_XML1);
            $offset = htmlspecialchars($break['time_offset'], ENT_XML1);

            $rendered .= <<<XML
  <vmap:AdBreak timeOffset="{$offset}" breakType="linear" breakId="{$breakId}">
    <vmap:AdSource id="{$breakId}-{$index}" allowMultipleAds="false" followRedirects="true">
      <vmap:VASTAdData>
{$vast}
      </vmap:VASTAdData>
    </vmap:AdSource>
  </vmap:AdBreak>

XML;
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<vmap:VMAP xmlns:vmap="http://www.iab.net/videosuite/vmap" version="1.0">
{$rendered}</vmap:VMAP>
XML;
    }
}
