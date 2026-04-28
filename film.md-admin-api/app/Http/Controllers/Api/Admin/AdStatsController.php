<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdCampaign;
use App\Models\AdEvent;
use App\Models\AdEventAggregate;
use App\Services\AdEventTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-campaign analytics endpoints powering the admin Reclame dashboard:
 *  - bar chart of event counts (impression/start/firstQuartile/.../complete/click/skip)
 *  - pie chart of country breakdown
 *  - paginated raw events table (drilldown, last 7 days)
 *  - exportable as Excel/CSV (handled by ExportController; this just returns JSON)
 */
class AdStatsController extends ApiController
{
    public function show(Request $request, AdCampaign $campaign): JsonResponse
    {
        $daysBack = max(1, min(90, (int) $request->integer('days', 30)));
        $cutoff = now()->subDays($daysBack)->toDateString();

        $aggregates = AdEventAggregate::query()
            ->where('ad_campaign_id', $campaign->id)
            ->where('date', '>=', $cutoff)
            ->get();

        $byEvent = $aggregates->groupBy('event_type')->map(fn ($rows) => (int) $rows->sum('count'));
        // Ensure all standard events appear (zero-fill for cleaner charts)
        foreach (AdEventTrackingService::ALL_EVENTS as $type) {
            $byEvent[$type] = (int) ($byEvent[$type] ?? 0);
        }
        $byEvent = $byEvent->sortKeys();

        $byCountry = $aggregates->whereIn('event_type', [
            AdEventTrackingService::EVENT_IMPRESSION,
            AdEventTrackingService::EVENT_START,
        ])
            ->groupBy(fn ($row) => $row->country_code ?? 'ZZ')
            ->map(fn ($rows) => (int) $rows->sum('count'))
            ->sortDesc();

        $totalCountryCount = max(1, $byCountry->sum());
        $countryBreakdown = $byCountry->map(fn (int $count, string $cc): array => [
            'country' => $cc,
            'count' => $count,
            'percent' => round(($count / $totalCountryCount) * 100, 2),
        ])->values();

        $byDay = $aggregates->groupBy(fn ($row) => $row->date instanceof \Carbon\Carbon ? $row->date->toDateString() : (string) $row->date)
            ->map(fn ($rows) => [
                'date' => $rows->first()->date instanceof \Carbon\Carbon ? $rows->first()->date->toDateString() : (string) $rows->first()->date,
                'impressions' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_IMPRESSION)->sum('count'),
                'completes' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_COMPLETE)->sum('count'),
                'clicks' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_CLICK)->sum('count'),
                'skips' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_SKIP)->sum('count'),
            ])
            ->values()
            ->sortBy('date')
            ->values();

        return response()->json([
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'company_name' => $campaign->company_name,
                'placement' => $campaign->placement,
                'status' => $campaign->status,
                'bid_amount' => $campaign->bid_amount,
                'click_through_url' => $campaign->click_through_url,
                'is_active' => $campaign->is_active,
                'starts_at' => $campaign->starts_at?->toIso8601String(),
                'ends_at' => $campaign->ends_at?->toIso8601String(),
                'rollups' => [
                    'impressions' => (int) $campaign->impressions_count,
                    'completes' => (int) $campaign->completes_count,
                    'clicks' => (int) $campaign->clicks_count,
                    'skips' => (int) $campaign->skips_count,
                    'ctr' => $campaign->impressions_count > 0
                        ? round(($campaign->clicks_count / $campaign->impressions_count) * 100, 2)
                        : 0,
                    'completion_rate' => $campaign->impressions_count > 0
                        ? round(($campaign->completes_count / $campaign->impressions_count) * 100, 2)
                        : 0,
                ],
            ],
            'events_chart' => $byEvent->map(fn (int $count, string $type) => [
                'event' => $type,
                'count' => $count,
            ])->values(),
            'country_chart' => $countryBreakdown,
            'daily_chart' => $byDay,
        ]);
    }

    public function events(Request $request, AdCampaign $campaign): JsonResponse
    {
        $perPage = max(10, min(200, (int) $request->integer('per_page', 50)));
        $events = AdEvent::query()
            ->where('ad_campaign_id', $campaign->id)
            ->when($request->query('event_type'), fn ($q, $t) => $q->where('event_type', $t))
            ->when($request->query('country_code'), fn ($q, $cc) => $q->where('country_code', $cc))
            ->orderByDesc('occurred_at')
            ->paginate($perPage);

        return response()->json([
            'items' => $events->getCollection()->map(fn (AdEvent $e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'country_code' => $e->country_code,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'ip_address' => $e->ip_address,
                'playback_session_id' => $e->playback_session_id,
                'content_id' => $e->content_id,
            ])->values(),
            'pagination' => [
                'page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }
}
