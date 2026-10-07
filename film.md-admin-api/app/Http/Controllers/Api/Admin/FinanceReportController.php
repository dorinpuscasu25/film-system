<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\PaymentTopUp;
use App\Models\VideoMonthlyCost;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use App\Services\RightsReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Data for the finance report the admin turns into Excel and PDF.
 *
 * Sales, VAT and holder shares come from the auditable reporting ledger; top-ups
 * and the per-film delivery costs are added around it so one document answers
 * "how much came in, from which films, and who is owed what".
 */
class FinanceReportController extends ApiController
{
    public function __construct(
        protected RightsReportingService $reporting,
        protected ContentScopeService $contentScope,
        protected AuditLogService $auditLog,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'content_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($filters['from'] ?? now()->subDays(29))->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now())->endOfDay();
        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();

        $user = $request->user();
        $isScoped = $this->contentScope->isScoped($user);
        $report = $this->reporting->financeReport($user, $filters);

        $this->auditLog->record('finance_report.generated', 'finance_report', null, $filters, $user, $request);

        return response()->json([
            'period' => ['from' => $filters['from'], 'to' => $filters['to']],
            'generated_by' => $user?->name,
            ...$report,
            // Money entering the platform is platform-wide; holders never see it.
            'top_ups' => $isScoped ? null : $this->topUps($from, $to),
            'costs' => $this->costs($request, $from, $to),
        ]);
    }

    private function topUps(Carbon $from, Carbon $to): array
    {
        $paid = PaymentTopUp::query()
            ->whereIn('status', [PaymentTopUp::STATUS_PAID, PaymentTopUp::STATUS_REFUNDED])
            ->whereBetween('credited_at', [$from, $to])
            ->get(['user_id', 'amount', 'currency', 'status']);

        return [
            'count' => $paid->count(),
            'amount' => round((float) $paid->sum('amount'), 2),
            'payers' => $paid->pluck('user_id')->filter()->unique()->count(),
            'refunded_count' => $paid->where('status', PaymentTopUp::STATUS_REFUNDED)->count(),
            'refunded_amount' => round((float) $paid->where('status', PaymentTopUp::STATUS_REFUNDED)->sum('amount'), 2),
            'currency' => $paid->first()?->currency ?? 'MDL',
        ];
    }

    /**
     * Monthly delivery costs and viewing for every month the period touches.
     */
    private function costs(Request $request, Carbon $from, Carbon $to): array
    {
        $months = [];
        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        $query = VideoMonthlyCost::query()->with(['content:id,original_title', 'format:id,quality'])->whereIn('month', $months);
        $this->contentScope->scopeContentQuery($request->user(), $query, 'video_monthly_costs.content_id');

        $rows = $query->orderBy('month')->get();

        return [
            'months' => $months,
            'usd_to_mdl_rate' => (float) ($rows->last()?->usd_to_mdl_rate ?? 0),
            'items' => $rows->map(fn (VideoMonthlyCost $row) => [
                'month' => $row->month,
                'title' => $row->content?->original_title ?? "Film #{$row->content_id}",
                'quality' => $row->format?->quality,
                'views' => (int) data_get($row->meta, 'views', 0),
                'watch_hours' => round(((float) data_get($row->meta, 'watch_time_seconds', 0)) / 3600, 1),
                'bandwidth_gb' => round((float) data_get($row->meta, 'bandwidth_gb', 0), 2),
                'storage_cost_usd' => round($row->storage_cost_usd, 2),
                'delivery_cost_usd' => round($row->delivery_cost_usd, 2),
                'drm_cost_usd' => round($row->drm_cost_usd, 2),
                'revenue_usd' => round($row->revenue_usd, 2),
                'profit_usd' => round($row->profit_usd, 2),
            ])->values(),
        ];
    }
}
