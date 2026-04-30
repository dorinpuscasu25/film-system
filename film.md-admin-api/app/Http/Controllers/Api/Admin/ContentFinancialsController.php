<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\ContentFormat;
use App\Models\ContentEntitlement;
use App\Models\CostSettingsVersion;
use App\Models\Offer;
use App\Models\VideoMonthlyCost;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContentFinancialsController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function show(Request $request, Content $content): JsonResponse
    {
        $user = $request->user();
        if ($this->contentScope->isScoped($user)
            && ! in_array($content->id, $this->contentScope->assignedContentIds($user), true)) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $monthsBack = max(1, min(24, (int) $request->integer('months', 12)));
        $cutoffMonth = now()->copy()->startOfMonth()->subMonths($monthsBack - 1);

        $costs = VideoMonthlyCost::query()
            ->with('format:id,quality,format_type,bunny_library_id,bunny_video_id')
            ->where('content_id', $content->id)
            ->where('month', '>=', $cutoffMonth->format('Y-m'))
            ->orderBy('month', 'desc')
            ->get();

        $rate = (float) ($costs->first()->usd_to_mdl_rate ?? CostSettingsVersion::query()
            ->where('is_active', true)
            ->value('usd_to_mdl_rate') ?? 1.0);

        $byMonth = $costs->groupBy('month')->map(function ($rows, $month) {
            $rows = collect($rows);

            return [
                'month' => $month,
                'rows' => $rows->map(fn (VideoMonthlyCost $row) => [
                    'format_id' => $row->content_format_id,
                    'quality' => $row->format?->quality,
                    'format_type' => $row->format?->format_type,
                    'storage_cost_usd' => round((float) $row->storage_cost_usd, 4),
                    'delivery_cost_usd' => round((float) $row->delivery_cost_usd, 4),
                    'drm_cost_usd' => round((float) $row->drm_cost_usd, 4),
                    'revenue_usd' => round((float) $row->revenue_usd, 4),
                    'profit_usd' => round((float) $row->profit_usd, 4),
                    'is_locked' => (bool) $row->is_locked,
                ])->values(),
                'totals' => [
                    'storage_cost_usd' => round((float) $rows->sum('storage_cost_usd'), 4),
                    'delivery_cost_usd' => round((float) $rows->sum('delivery_cost_usd'), 4),
                    'drm_cost_usd' => round((float) $rows->sum('drm_cost_usd'), 4),
                    'revenue_usd' => round((float) $rows->sum('revenue_usd'), 4),
                    'profit_usd' => round((float) $rows->sum('profit_usd'), 4),
                ],
            ];
        })->values();

        $grandTotals = [
            'storage_cost_usd' => round((float) $costs->sum('storage_cost_usd'), 4),
            'delivery_cost_usd' => round((float) $costs->sum('delivery_cost_usd'), 4),
            'drm_cost_usd' => round((float) $costs->sum('drm_cost_usd'), 4),
            'revenue_usd' => round((float) $costs->sum('revenue_usd'), 4),
            'profit_usd' => round((float) $costs->sum('profit_usd'), 4),
        ];

        $totalSales = ContentEntitlement::query()
            ->where('content_id', $content->id)
            ->where('status', 'active')
            ->count();

        $offers = Offer::query()
            ->where('content_id', $content->id)
            ->orderBy('quality')
            ->get(['id', 'quality', 'price_amount', 'currency', 'is_active'])
            ->map(fn (Offer $offer) => [
                'id' => $offer->id,
                'quality' => $offer->quality,
                'price' => round((float) $offer->price_amount, 2),
                'currency' => $offer->currency,
                'is_active' => (bool) $offer->is_active,
            ]);

        $formats = ContentFormat::query()
            ->where('content_id', $content->id)
            ->orderBy('quality')
            ->get(['id', 'quality', 'format_type', 'bunny_library_id', 'bunny_video_id', 'is_active']);

        return response()->json([
            'content_id' => $content->id,
            'content_title' => $content->original_title,
            'usd_to_mdl_rate' => $rate,
            'months' => $byMonth,
            'totals' => $grandTotals,
            'totals_mdl' => [
                'storage_cost' => round($grandTotals['storage_cost_usd'] * $rate, 2),
                'delivery_cost' => round($grandTotals['delivery_cost_usd'] * $rate, 2),
                'drm_cost' => round($grandTotals['drm_cost_usd'] * $rate, 2),
                'total_cost' => round(
                    ($grandTotals['storage_cost_usd'] + $grandTotals['delivery_cost_usd'] + $grandTotals['drm_cost_usd']) * $rate,
                    2,
                ),
                'revenue' => round($grandTotals['revenue_usd'] * $rate, 2),
                'profit' => round($grandTotals['profit_usd'] * $rate, 2),
            ],
            'sales_count' => $totalSales,
            'offers' => $offers,
            'formats' => $formats,
        ]);
    }
}
