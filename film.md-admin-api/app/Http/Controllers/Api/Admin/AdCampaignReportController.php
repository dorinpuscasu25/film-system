<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdCampaign;
use App\Models\AdEvent;
use App\Models\AdEventAggregate;
use App\Services\AdEventTrackingService;
use App\Services\ContentScopeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Advertiser-facing performance report for one campaign.
 *
 * Distinct from {@see AdStatsController}, which is an operational dashboard:
 * this produces the numbers an advertiser expects to see when judging whether
 * the spend worked — the completion funnel, view-through and click-through
 * rates, unique reach, and breakdowns by country, platform and title.
 */
class AdCampaignReportController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function show(Request $request, AdCampaign $campaign): JsonResponse
    {
        $user = $request->user();
        $this->contentScope->assertCanAccessAdCampaign($user, $campaign);

        [$from, $to] = $this->resolveRange($request);

        $aggregates = AdEventAggregate::query()
            ->where('ad_campaign_id', $campaign->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when(
                $this->contentScope->isScoped($user),
                fn ($query) => $query->whereIn('content_id', $this->contentScope->assignedContentIds($user)),
            )
            ->get();

        $count = fn (string $event): int => (int) $aggregates->where('event_type', $event)->sum('count');

        $impressions = $count(AdEventTrackingService::EVENT_IMPRESSION);
        $starts = $count(AdEventTrackingService::EVENT_START);
        $q1 = $count(AdEventTrackingService::EVENT_FIRST_QUARTILE);
        $mid = $count(AdEventTrackingService::EVENT_MIDPOINT);
        $q3 = $count(AdEventTrackingService::EVENT_THIRD_QUARTILE);
        $completes = $count(AdEventTrackingService::EVENT_COMPLETE);
        $clicks = $count(AdEventTrackingService::EVENT_CLICK);
        $skips = $count(AdEventTrackingService::EVENT_SKIP);

        return response()->json([
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'company_name' => $campaign->company_name,
                'placement' => $campaign->placement,
                'status' => $campaign->status,
                'starts_at' => $campaign->starts_at?->toIso8601String(),
                'ends_at' => $campaign->ends_at?->toIso8601String(),
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $from->diffInDays($to) + 1,
            ],
            'headline' => [
                'impressions' => $impressions,
                'completed_views' => $completes,
                'clicks' => $clicks,
                'unique_reach' => $this->uniqueReach($campaign, $from, $to),
                // View-through rate: the share of served ads watched to the end.
                'view_through_rate' => $this->rate($completes, $impressions),
                'click_through_rate' => $this->rate($clicks, $impressions),
                'skip_rate' => $this->rate($skips, $impressions),
                'average_completion_percent' => $this->averageCompletion($impressions, $q1, $mid, $q3, $completes),
            ],
            // Ordered so the advertiser can see exactly where viewers drop off.
            'funnel' => [
                ['stage' => 'impression', 'count' => $impressions, 'percent' => $this->rate($impressions, $impressions)],
                ['stage' => 'start', 'count' => $starts, 'percent' => $this->rate($starts, $impressions)],
                ['stage' => 'firstQuartile', 'count' => $q1, 'percent' => $this->rate($q1, $impressions)],
                ['stage' => 'midpoint', 'count' => $mid, 'percent' => $this->rate($mid, $impressions)],
                ['stage' => 'thirdQuartile', 'count' => $q3, 'percent' => $this->rate($q3, $impressions)],
                ['stage' => 'complete', 'count' => $completes, 'percent' => $this->rate($completes, $impressions)],
            ],
            'by_country' => $this->breakdown($aggregates, 'country_code', 'ZZ'),
            'by_platform' => $this->breakdown($aggregates, 'platform', 'unknown'),
            'by_title' => $this->titleBreakdown($aggregates),
            'daily' => $this->daily($aggregates),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->query('to'))->endOfDay()
            : Carbon::today();
        $from = $request->filled('from')
            ? Carbon::parse((string) $request->query('from'))->startOfDay()
            : $to->copy()->subDays(max(1, min(365, (int) $request->integer('days', 30))) - 1);

        return $from->greaterThan($to) ? [$to->copy()->startOfDay(), $from->copy()->endOfDay()] : [$from, $to];
    }

    /**
     * Distinct playback sessions that saw the ad — the closest proxy we have to
     * unique people, since an anonymous viewer has no stable identifier.
     */
    private function uniqueReach(AdCampaign $campaign, Carbon $from, Carbon $to): int
    {
        return (int) AdEvent::query()
            ->where('ad_campaign_id', $campaign->id)
            ->where('event_type', AdEventTrackingService::EVENT_IMPRESSION)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNotNull('playback_session_id')
            ->distinct('playback_session_id')
            ->count('playback_session_id');
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0.0;
    }

    /**
     * Weighted average of how much of the creative was watched, using the
     * quartile beacons as the only progress signal VAST gives us.
     */
    private function averageCompletion(int $impressions, int $q1, int $mid, int $q3, int $completes): float
    {
        if ($impressions <= 0) {
            return 0.0;
        }

        $weighted = ($q1 * 25) + (($mid - $q1) * 50) + (($q3 - $mid) * 75) + ($completes * 100);

        return round(max(0, min(100, $weighted / $impressions)), 2);
    }

    /**
     * @param \Illuminate\Support\Collection<int, AdEventAggregate> $aggregates
     * @return array<int, array<string, mixed>>
     */
    private function breakdown(\Illuminate\Support\Collection $aggregates, string $column, string $fallback): array
    {
        $grouped = $aggregates->groupBy(fn (AdEventAggregate $row) => $row->{$column} ?? $fallback);
        $totalImpressions = max(1, (int) $aggregates->where('event_type', AdEventTrackingService::EVENT_IMPRESSION)->sum('count'));

        return $grouped->map(function ($rows, $key) use ($totalImpressions): array {
            $impressions = (int) $rows->where('event_type', AdEventTrackingService::EVENT_IMPRESSION)->sum('count');
            $completes = (int) $rows->where('event_type', AdEventTrackingService::EVENT_COMPLETE)->sum('count');
            $clicks = (int) $rows->where('event_type', AdEventTrackingService::EVENT_CLICK)->sum('count');

            return [
                'key' => (string) $key,
                'impressions' => $impressions,
                'completed_views' => $completes,
                'clicks' => $clicks,
                'share_percent' => $this->rate($impressions, $totalImpressions),
                'view_through_rate' => $this->rate($completes, $impressions),
                'click_through_rate' => $this->rate($clicks, $impressions),
            ];
        })
            ->sortByDesc('impressions')
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, AdEventAggregate> $aggregates
     * @return array<int, array<string, mixed>>
     */
    private function titleBreakdown(\Illuminate\Support\Collection $aggregates): array
    {
        $contentIds = $aggregates->pluck('content_id')->filter()->unique()->values();
        if ($contentIds->isEmpty()) {
            return [];
        }

        $titles = \App\Models\Content::query()
            ->whereIn('id', $contentIds)
            ->pluck('original_title', 'id');

        return $aggregates
            ->whereNotNull('content_id')
            ->groupBy('content_id')
            ->map(function ($rows, $contentId) use ($titles): array {
                $impressions = (int) $rows->where('event_type', AdEventTrackingService::EVENT_IMPRESSION)->sum('count');
                $completes = (int) $rows->where('event_type', AdEventTrackingService::EVENT_COMPLETE)->sum('count');

                return [
                    'content_id' => (int) $contentId,
                    'title' => $titles[$contentId] ?? ('#'.$contentId),
                    'impressions' => $impressions,
                    'completed_views' => $completes,
                    'view_through_rate' => $this->rate($completes, $impressions),
                ];
            })
            ->sortByDesc('impressions')
            ->values()
            ->all();
    }

    /**
     * @param \Illuminate\Support\Collection<int, AdEventAggregate> $aggregates
     * @return array<int, array<string, mixed>>
     */
    private function daily(\Illuminate\Support\Collection $aggregates): array
    {
        return $aggregates
            ->groupBy(fn (AdEventAggregate $row) => $row->date instanceof Carbon
                ? $row->date->toDateString()
                : (string) $row->date)
            ->map(fn ($rows, $date): array => [
                'date' => (string) $date,
                'impressions' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_IMPRESSION)->sum('count'),
                'completed_views' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_COMPLETE)->sum('count'),
                'clicks' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_CLICK)->sum('count'),
                'skips' => (int) $rows->where('event_type', AdEventTrackingService::EVENT_SKIP)->sum('count'),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }
}
