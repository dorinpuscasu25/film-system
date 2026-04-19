<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentCreator;
use App\Models\CostSettingsVersion;
use App\Models\VideoMonthlyCost;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CostSettingsController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $isScoped = $this->contentScope->isScoped(request()->user());
        $assignedContentIds = $this->contentScope->assignedContentIds(request()->user());
        $versions = CostSettingsVersion::query()
            ->orderByDesc('effective_from')
            ->limit(12)
            ->get();

        $currentMonth = now()->format('Y-m');
        $monthlyCosts = VideoMonthlyCost::query()
            ->with(['content', 'format'])
            ->where('month', $currentMonth)
            ->when($isScoped, fn ($query) => $query->whereIn('content_id', $assignedContentIds))
            ->orderByDesc('profit_usd')
            ->limit(20)
            ->get();

        $creatorIds = ! $isScoped
            ? []
            : ($assignedContentIds === []
            ? []
            : DB::table('content_creator_assignments')
                ->whereIn('content_id', $assignedContentIds)
                ->pluck('content_creator_id')
                ->unique()
                ->values()
                ->all());

        $creatorStatements = \App\Models\CreatorMonthlyStatement::query()
            ->with('creator')
            ->where('month', $currentMonth)
            ->when($isScoped, fn ($query) => $query->whereIn('content_creator_id', $creatorIds))
            ->orderByDesc('payout_usd')
            ->limit(20)
            ->get();

        return response()->json([
            'current' => $versions->first(),
            'versions' => $versions,
            'monthly_costs' => $monthlyCosts->map(fn (VideoMonthlyCost $row) => [
                'id' => $row->id,
                'content_id' => $row->content_id,
                'content_title' => $row->content?->original_title,
                'quality' => $row->format?->quality,
                'month' => $row->month,
                'storage_cost_usd' => $row->storage_cost_usd,
                'delivery_cost_usd' => $row->delivery_cost_usd,
                'drm_cost_usd' => $row->drm_cost_usd,
                'revenue_usd' => $row->revenue_usd,
                'profit_usd' => $row->profit_usd,
                'is_locked' => $row->is_locked,
            ])->values(),
            'creator_statements' => $creatorStatements->map(fn ($row) => [
                'id' => $row->id,
                'creator_id' => $row->content_creator_id,
                'creator_name' => $row->creator?->name,
                'month' => $row->month,
                'revenue_usd' => $row->revenue_usd,
                'costs_usd' => $row->costs_usd,
                'payout_usd' => $row->payout_usd,
                'profit_usd' => $row->profit_usd,
                'is_locked' => $row->is_locked,
            ])->values(),
            'creators' => ContentCreator::query()
                ->where('is_active', true)
                ->when($isScoped, fn ($query) => $query->whereIn('id', $creatorIds))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'company_name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'storage_cost_per_gb_day' => ['required', 'numeric', 'min:0'],
            'delivery_cost_per_gb' => ['required', 'numeric', 'min:0'],
            'drm_cost_per_license' => ['required', 'numeric', 'min:0'],
            'usd_to_mdl_rate' => ['required', 'numeric', 'min:0.0001'],
        ]);

        CostSettingsVersion::query()
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'effective_until' => now(),
            ]);

        $version = CostSettingsVersion::query()->create([
            ...$payload,
            'effective_from' => now(),
            'is_active' => true,
        ]);

        $this->auditLog->record(
            'cost_settings.updated',
            'cost_settings_version',
            $version->id,
            $payload,
            $request->user(),
            $request,
        );

        return response()->json([
            'version' => $version,
        ], Response::HTTP_CREATED);
    }
}
