<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdCampaign;
use App\Models\Content;
use App\Services\AdTargetingService;
use App\Services\VastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Debug endpoint that resolves which campaign would be served for a given
 * content + country + placement, returns the chosen campaign metadata, the
 * VAST XML, the tracking pixel URLs, and the eligibility breakdown for all
 * candidates (so you can see why a campaign was excluded).
 */
class AdTestController extends ApiController
{
    public function __construct(
        protected AdTargetingService $targeting,
        protected VastService $vast,
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content_id' => ['required', 'integer'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'placement' => ['required', 'string', 'in:pre-roll,mid-roll,post-roll'],
            'group' => ['nullable', 'string', 'max:32'],
            'session_id' => ['nullable', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $content = Content::query()->findOrFail($data['content_id']);
        $country = isset($data['country_code']) ? strtoupper((string) $data['country_code']) : null;
        $group = (string) ($data['group'] ?? 'movies');

        $eligible = $this->targeting->eligibleCampaigns($data['placement'], $content, $country, $group);
        $chosen = $this->targeting->pickForSession(
            $data['placement'],
            $content,
            $country,
            $group,
            $data['session_id'] ?? null,
            $data['user_id'] ?? null,
        );

        $allCandidates = AdCampaign::query()
            ->with('creatives')
            ->where('placement', $data['placement'])
            ->orderByDesc('bid_amount')
            ->get();

        $breakdown = $allCandidates->map(function (AdCampaign $c) use ($eligible, $chosen, $country, $group, $content) {
            $reasons = [];
            if (! $c->is_active) {
                $reasons[] = 'is_active = false';
            }
            if ($c->status !== AdCampaign::STATUS_ACTIVE) {
                $reasons[] = "status = {$c->status} (need active)";
            }
            $now = now();
            if ($c->starts_at !== null && $c->starts_at->gt($now)) {
                $reasons[] = 'starts_at in future';
            }
            if ($c->ends_at !== null && $c->ends_at->lt($now)) {
                $reasons[] = 'ends_at in past';
            }
            $included = $c->target_content_ids ?? [];
            if (! empty($included) && ! in_array($content->id, array_map('intval', $included), true)) {
                $reasons[] = 'content_id not in target_content_ids';
            }
            $excluded = $c->target_excluded_content_ids ?? [];
            if (! empty($excluded) && in_array($content->id, array_map('intval', $excluded), true)) {
                $reasons[] = 'content_id in target_excluded_content_ids';
            }
            $countries = array_map('strtoupper', $c->target_countries ?? []);
            if (! empty($countries) && $countries !== ['ALL']) {
                if ($country === null || ! in_array($country, $countries, true)) {
                    $reasons[] = "country '{$country}' not in target_countries";
                }
            }
            $groups = $c->target_groups ?? [];
            if (! empty($groups) && ! in_array($group, $groups, true)) {
                $reasons[] = "group '{$group}' not in target_groups";
            }

            return [
                'id' => $c->id,
                'name' => $c->name,
                'bid' => (float) $c->bid_amount,
                'placement' => $c->placement,
                'eligible' => $eligible->contains('id', $c->id),
                'chosen' => $chosen?->id === $c->id,
                'reasons_excluded' => $reasons,
                'creatives_count' => $c->creatives->count(),
            ];
        })->values();

        $vastXml = null;
        $trackingUrls = [];
        if ($chosen !== null) {
            $base = rtrim((string) config('app.url'), '/').'/api/v1/ads/track';
            $vastXml = $this->vast->buildVastXml($chosen, $base, $data['session_id'] ?? null);
            // Extract pixel URLs from XML for clarity
            preg_match_all('/<URL>(<!\[CDATA\[)?([^<]+)/', $vastXml, $matches);
            $trackingUrls = $matches[2] ?? [];
        }

        return response()->json([
            'inputs' => [
                'content_id' => $content->id,
                'content_title' => $content->original_title,
                'country_code' => $country,
                'placement' => $data['placement'],
                'group' => $group,
            ],
            'chosen' => $chosen ? [
                'id' => $chosen->id,
                'name' => $chosen->name,
                'company_name' => $chosen->company_name,
                'bid_amount' => (float) $chosen->bid_amount,
                'placement' => $chosen->placement,
                'skip_offset_seconds' => $chosen->skip_offset_seconds,
                'click_through_url' => $chosen->click_through_url,
                'creative' => $chosen->creatives->first() ? [
                    'media_url' => $chosen->creatives->first()->media_url,
                    'duration_seconds' => $chosen->creatives->first()->duration_seconds,
                    'mime_type' => $chosen->creatives->first()->mime_type,
                ] : null,
            ] : null,
            'vast_xml' => $vastXml,
            'tracking_pixels' => $trackingUrls,
            'eligible_count' => $eligible->count(),
            'candidates' => $breakdown,
        ]);
    }
}
