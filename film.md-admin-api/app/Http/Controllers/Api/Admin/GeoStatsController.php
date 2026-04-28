<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates user/views distribution by country, sourced from
 * `bunny_stream_stats_daily.country_breakdown` (per video) and
 * `playback_sessions.country_code` (storefront).
 *
 * Caiet de sarcini §8 — Distribuție geografică utilizatori.
 */
class GeoStatsController extends ApiController
{
    public function __construct(
        protected ContentScopeService $scope,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $assigned = $this->scope->assignedContentIds($user);
        $isScoped = $this->scope->isScoped($user);

        $daysBack = max(1, min(365, (int) $request->integer('days', 30)));
        $cutoff = now()->subDays($daysBack)->toDateString();

        $sessions = DB::table('playback_sessions')
            ->selectRaw('country_code, COUNT(*) as sessions, COUNT(DISTINCT user_id) as users')
            ->whereNotNull('country_code')
            ->where('started_at', '>=', $cutoff)
            ->when($isScoped, fn ($q) => $q->whereIn('content_id', $assigned))
            ->groupBy('country_code')
            ->orderByDesc('sessions')
            ->get();

        $bunnyAggregates = DB::connection('analytics')->table('bunny_stream_stats_daily')
            ->where('date', '>=', $cutoff)
            ->when($isScoped, fn ($q) => $q->whereIn('content_id', $assigned))
            ->get(['country_breakdown', 'views']);

        $countryViews = [];
        foreach ($bunnyAggregates as $row) {
            $cb = is_string($row->country_breakdown) ? json_decode($row->country_breakdown, true) : ($row->country_breakdown ?? []);
            if (! is_array($cb)) {
                continue;
            }
            foreach ($cb as $cc => $count) {
                $cc = strtoupper((string) $cc);
                $countryViews[$cc] = ($countryViews[$cc] ?? 0) + (int) $count;
            }
        }

        $totalViews = max(1, array_sum($countryViews));
        $byCountry = [];
        foreach ($sessions as $row) {
            $cc = strtoupper((string) $row->country_code);
            $byCountry[$cc] = [
                'country' => $cc,
                'sessions' => (int) $row->sessions,
                'users' => (int) $row->users,
                'views' => (int) ($countryViews[$cc] ?? 0),
            ];
        }
        // include countries seen only via Bunny stats
        foreach ($countryViews as $cc => $views) {
            if (! isset($byCountry[$cc])) {
                $byCountry[$cc] = [
                    'country' => $cc,
                    'sessions' => 0,
                    'users' => 0,
                    'views' => $views,
                ];
            }
        }
        foreach ($byCountry as &$row) {
            $row['percent'] = round(($row['views'] / $totalViews) * 100, 2);
        }
        unset($row);
        usort($byCountry, fn ($a, $b) => $b['views'] <=> $a['views']);

        return response()->json([
            'days' => $daysBack,
            'totals' => [
                'total_views' => array_sum($countryViews),
                'unique_countries' => count($byCountry),
            ],
            'countries' => array_values($byCountry),
        ]);
    }
}
