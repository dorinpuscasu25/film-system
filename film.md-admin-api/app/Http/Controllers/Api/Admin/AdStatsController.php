<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdCampaign;
use App\Models\AdEvent;
use App\Models\AdEventAggregate;
use App\Models\Content;
use App\Services\AdEventTrackingService;
use App\Services\ContentScopeService;
use Carbon\Carbon;
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
    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function show(Request $request, AdCampaign $campaign): JsonResponse
    {
        $user = $request->user();
        $this->contentScope->assertCanAccessAdCampaign($user, $campaign);
        $isScoped = $this->contentScope->isScoped($user);
        $daysBack = max(1, min(90, (int) $request->integer('days', 30)));
        $cutoff = now()->subDays($daysBack)->toDateString();

        $aggregates = AdEventAggregate::query()
            ->where('ad_campaign_id', $campaign->id)
            ->where('date', '>=', $cutoff)
            ->when(
                $isScoped,
                fn ($query) => $query->whereIn('content_id', $this->contentScope->assignedContentIds($user)),
            )
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

        $dateKey = fn ($row): string => $row->date instanceof Carbon ? $row->date->toDateString() : substr((string) $row->date, 0, 10);
        $grouped = $aggregates->groupBy($dateKey);

        // Zero-filled so a report reads as a continuous calendar, not a list
        // with gaps that a reader has to notice on their own.
        $byDay = collect();
        for ($day = Carbon::parse($cutoff); $day->lte(today()); $day->addDay()) {
            $rows = $grouped->get($day->toDateString(), collect());
            $byDay->push(['date' => $day->toDateString()] + $this->totals($rows));
        }

        $byPlatform = $aggregates
            ->groupBy(fn ($row) => $row->platform ?: 'unknown')
            ->map(fn ($rows, string $platform): array => ['platform' => $platform] + $this->totals($rows))
            ->sortByDesc('impressions')
            ->values();

        $byContent = $aggregates
            ->groupBy(fn ($row) => (int) ($row->content_id ?? 0))
            ->map(fn ($rows, int $contentId): array => ['content_id' => $contentId ?: null] + $this->totals($rows))
            ->sortByDesc('impressions')
            ->values();
        $titles = Content::query()
            ->whereIn('id', $byContent->pluck('content_id')->filter())
            ->pluck('original_title', 'id');
        $byContent = $byContent->map(fn (array $row): array => $row + [
            'title' => $row['content_id'] !== null ? ($titles[$row['content_id']] ?? null) : null,
        ]);
        $impressions = $isScoped
            ? (int) ($byEvent[AdEventTrackingService::EVENT_IMPRESSION] ?? 0)
            : (int) $campaign->impressions_count;
        $completes = $isScoped
            ? (int) ($byEvent[AdEventTrackingService::EVENT_COMPLETE] ?? 0)
            : (int) $campaign->completes_count;
        $clicks = $isScoped
            ? (int) ($byEvent[AdEventTrackingService::EVENT_CLICK] ?? 0)
            : (int) $campaign->clicks_count;
        $skips = $isScoped
            ? (int) ($byEvent[AdEventTrackingService::EVENT_SKIP] ?? 0)
            : (int) $campaign->skips_count;

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
                    'impressions' => $impressions,
                    'completes' => $completes,
                    'clicks' => $clicks,
                    'skips' => $skips,
                    'ctr' => $impressions > 0
                        ? round(($clicks / $impressions) * 100, 2)
                        : 0,
                    'completion_rate' => $impressions > 0
                        ? round(($completes / $impressions) * 100, 2)
                        : 0,
                ],
            ],
            'events_chart' => $byEvent->map(fn (int $count, string $type) => [
                'event' => $type,
                'count' => $count,
            ])->values(),
            'country_chart' => $countryBreakdown,
            'daily_chart' => $byDay,
            'period' => [
                'days' => $daysBack,
                'from' => $cutoff,
                'to' => today()->toDateString(),
            ],
            // Everything below covers only the selected period, unlike the
            // lifetime counters in `rollups`, so a report stays self-consistent.
            'period_totals' => $this->totals($aggregates),
            'platform_chart' => $byPlatform,
            'content_chart' => $byContent,
        ]);
    }

    /**
     * Event counts and the derived rates for a set of aggregate rows.
     *
     * @return array<string, int|float>
     */
    private function totals($rows): array
    {
        $count = fn (string $event): int => (int) $rows->where('event_type', $event)->sum('count');
        $impressions = $count(AdEventTrackingService::EVENT_IMPRESSION);
        $completes = $count(AdEventTrackingService::EVENT_COMPLETE);
        $clicks = $count(AdEventTrackingService::EVENT_CLICK);
        $rate = fn (int $part): float => $impressions > 0 ? round(($part / $impressions) * 100, 2) : 0.0;

        return [
            'impressions' => $impressions,
            'starts' => $count(AdEventTrackingService::EVENT_START),
            'first_quartile' => $count(AdEventTrackingService::EVENT_FIRST_QUARTILE),
            'midpoint' => $count(AdEventTrackingService::EVENT_MIDPOINT),
            'third_quartile' => $count(AdEventTrackingService::EVENT_THIRD_QUARTILE),
            'completes' => $completes,
            'clicks' => $clicks,
            'skips' => $count(AdEventTrackingService::EVENT_SKIP),
            'completion_rate' => $rate($completes),
            'ctr' => $rate($clicks),
        ];
    }

    public function events(Request $request, AdCampaign $campaign): JsonResponse
    {
        $user = $request->user();
        $this->contentScope->assertCanAccessAdCampaign($user, $campaign);
        $perPage = max(10, min(200, (int) $request->integer('per_page', 50)));
        $events = AdEvent::query()
            ->where('ad_campaign_id', $campaign->id)
            ->when(
                $this->contentScope->isScoped($user),
                fn ($query) => $query->whereIn('content_id', $this->contentScope->assignedContentIds($user)),
            )
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
