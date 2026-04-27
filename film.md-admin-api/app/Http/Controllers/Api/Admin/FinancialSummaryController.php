<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\CostSettingsVersion;
use App\Models\VideoMonthlyCost;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinancialSummaryController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $isScoped = $this->contentScope->isScoped($user);
        $assignedContentIds = $this->contentScope->assignedContentIds($user);

        $currentMonth = now()->format('Y-m');
        $previousMonth = now()->copy()->subMonth()->format('Y-m');

        $applyScope = function ($query) use ($isScoped, $assignedContentIds) {
            if ($isScoped) {
                $query->whereIn('content_id', $assignedContentIds);
            }
        };

        $currentRows = VideoMonthlyCost::query()
            ->where('month', $currentMonth)
            ->tap($applyScope)
            ->get();

        $previousRows = VideoMonthlyCost::query()
            ->where('month', $previousMonth)
            ->tap($applyScope)
            ->get();

        $allRows = VideoMonthlyCost::query()
            ->tap($applyScope)
            ->get();

        $rate = (float) ($allRows->first()->usd_to_mdl_rate
            ?? CostSettingsVersion::query()->where('is_active', true)->value('usd_to_mdl_rate')
            ?? 1.0);

        $monthlyChart = VideoMonthlyCost::query()
            ->tap($applyScope)
            ->selectRaw('month, SUM(storage_cost_usd + delivery_cost_usd + drm_cost_usd) as total_cost_usd, SUM(revenue_usd) as revenue_usd, SUM(profit_usd) as profit_usd, AVG(usd_to_mdl_rate) as rate')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function ($row) use ($rate): array {
                $rowRate = (float) ($row->rate ?? $rate);

                return [
                    'month' => (string) $row->month,
                    'total_cost_mdl' => round((float) $row->total_cost_usd * $rowRate, 2),
                    'revenue_mdl' => round((float) $row->revenue_usd * $rowRate, 2),
                    'profit_mdl' => round((float) $row->profit_usd * $rowRate, 2),
                ];
            });

        $sumCostUsd = fn ($rows) => (float) $rows->sum(fn (VideoMonthlyCost $r) => $r->storage_cost_usd + $r->delivery_cost_usd + $r->drm_cost_usd);

        $currentCostMdl = round($sumCostUsd($currentRows) * $rate, 2);
        $previousCostMdl = round($sumCostUsd($previousRows) * $rate, 2);
        $totalCostMdl = round($sumCostUsd($allRows) * $rate, 2);
        $totalRevenueMdl = round((float) $allRows->sum('revenue_usd') * $rate, 2);
        $totalProfitMdl = round((float) $allRows->sum('profit_usd') * $rate, 2);

        $topMovies = VideoMonthlyCost::query()
            ->with('content:id,original_title,slug,poster_url')
            ->tap($applyScope)
            ->where('month', $currentMonth)
            ->selectRaw('content_id, SUM(storage_cost_usd + delivery_cost_usd + drm_cost_usd) as total_cost_usd, SUM(revenue_usd) as revenue_usd, SUM(profit_usd) as profit_usd')
            ->groupBy('content_id')
            ->orderByDesc('profit_usd')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'content_id' => (int) $row->content_id,
                'title' => $row->content?->original_title,
                'slug' => $row->content?->slug,
                'poster_url' => $row->content?->poster_url,
                'cost_mdl' => round((float) $row->total_cost_usd * $rate, 2),
                'revenue_mdl' => round((float) $row->revenue_usd * $rate, 2),
                'profit_mdl' => round((float) $row->profit_usd * $rate, 2),
            ]);

        return response()->json([
            'currency' => 'MDL',
            'usd_to_mdl_rate' => $rate,
            'current_month' => [
                'label' => Carbon::parse($currentMonth)->translatedFormat('F Y'),
                'cost_mdl' => $currentCostMdl,
            ],
            'previous_month' => [
                'label' => Carbon::parse($previousMonth)->translatedFormat('F Y'),
                'cost_mdl' => $previousCostMdl,
            ],
            'total_costs_mdl' => $totalCostMdl,
            'total_revenue_mdl' => $totalRevenueMdl,
            'total_profit_mdl' => $totalProfitMdl,
            'monthly_chart' => $monthlyChart,
            'top_movies_current_month' => $topMovies,
        ]);
    }
}
