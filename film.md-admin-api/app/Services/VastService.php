<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Content;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class VastService
{
    public function resolveCampaign(?Content $content, ?string $countryCode, string $group = 'movies', string $placement = 'pre-roll'): ?AdCampaign
    {
        $now = Carbon::now();

        $campaigns = AdCampaign::query()
            ->with(['creatives', 'targetingRules'])
            ->where('is_active', true)
            ->where('status', AdCampaign::STATUS_ACTIVE)
            ->where('placement', $placement)
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByDesc('bid_amount')
            ->get();

        foreach ($campaigns as $campaign) {
            if ($campaign->creatives->where('is_active', true)->isEmpty()) {
                continue;
            }

            if ($this->matchesTargeting($campaign->targetingRules, $content?->id, $countryCode, $group)) {
                return $campaign;
            }
        }

        return null;
    }

    public function buildVastXml(AdCampaign $campaign, string $trackingBaseUrl): string
    {
        $creative = $campaign->creatives->where('is_active', true)->sortByDesc('id')->first();
        $duration = gmdate('H:i:s', max(1, (int) ($creative?->duration_seconds ?? 1)));
        $mediaUrl = htmlspecialchars((string) $creative?->media_url, ENT_XML1);
        $clickUrl = htmlspecialchars((string) ($campaign->click_through_url ?: $campaign->vast_tag_url ?: ''), ENT_XML1);
        $impressionUrl = htmlspecialchars("{$trackingBaseUrl}?event=impression&campaign_id={$campaign->id}", ENT_XML1);
        $completeUrl = htmlspecialchars("{$trackingBaseUrl}?event=complete&campaign_id={$campaign->id}", ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<VAST version="3.0">
  <Ad id="{$campaign->id}">
    <InLine>
      <AdSystem>filmoteca.md</AdSystem>
      <AdTitle>{$campaign->name}</AdTitle>
      <Impression>{$impressionUrl}</Impression>
      <Creatives>
        <Creative>
          <Linear skipoffset="00:00:{$campaign->skip_offset_seconds}">
            <Duration>{$duration}</Duration>
            <TrackingEvents>
              <Tracking event="complete">{$completeUrl}</Tracking>
            </TrackingEvents>
            <VideoClicks>
              <ClickThrough>{$clickUrl}</ClickThrough>
            </VideoClicks>
            <MediaFiles>
              <MediaFile delivery="progressive" type="{$creative?->mime_type}" width="{$creative?->width}" height="{$creative?->height}">
                {$mediaUrl}
              </MediaFile>
            </MediaFiles>
          </Linear>
        </Creative>
      </Creatives>
    </InLine>
  </Ad>
</VAST>
XML;
    }

    protected function matchesTargeting(Collection $rules, ?int $contentId, ?string $countryCode, string $group): bool
    {
        if ($rules->isEmpty()) {
            return true;
        }

        $includeRules = $rules->where('is_include_rule', true);
        $excludeRules = $rules->where('is_include_rule', false);

        foreach ($excludeRules as $rule) {
            if (($rule->country_code === null || $rule->country_code === $countryCode)
                && ($rule->allowed_group === null || $rule->allowed_group === $group)
                && ($rule->content_id === null || $rule->content_id === $contentId)) {
                return false;
            }
        }

        if ($includeRules->isEmpty()) {
            return true;
        }

        foreach ($includeRules as $rule) {
            if (($rule->country_code === null || $rule->country_code === $countryCode)
                && ($rule->allowed_group === null || $rule->allowed_group === $group)
                && ($rule->content_id === null || $rule->content_id === $contentId)) {
                return true;
            }
        }

        return false;
    }
}
