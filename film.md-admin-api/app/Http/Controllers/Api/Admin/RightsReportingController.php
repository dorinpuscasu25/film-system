<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\ContentEntitlement;
use App\Models\CreatorContractVersion;
use App\Models\CreatorFiscalProfile;
use App\Models\ReportingSettingsVersion;
use App\Services\AuditLogService;
use App\Services\RightsReportingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class RightsReportingController extends ApiController
{
    public function __construct(
        protected RightsReportingService $reporting,
        protected AuditLogService $auditLog,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'content_id' => ['nullable', 'integer'], 'country_code' => ['nullable', 'string', 'size:2'],
            'market' => ['nullable', 'in:all,domestic,export'], 'quality' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json($this->reporting->dashboard($request->user(), $filters));
    }

    public function profiles(): JsonResponse
    {
        return response()->json([
            'creators' => \App\Models\ContentCreator::query()
                ->with(['contents:id,original_title', 'contractVersions.content:id,original_title', 'fiscalProfiles'])
                ->orderBy('name')->get()->map(fn ($creator) => [
                    'id' => $creator->id, 'name' => $creator->name, 'company_name' => $creator->company_name,
                    'email' => $creator->email, 'is_active' => $creator->is_active,
                    'contents' => $creator->contents->map(fn ($content) => ['id' => $content->id, 'title' => $content->original_title])->values(),
                    'contracts' => $creator->contractVersions->sortByDesc('effective_from')->map(fn ($contract) => [
                        ...$contract->only(['id', 'content_id', 'share_percent', 'territories', 'effective_from', 'effective_until', 'contract_reference', 'status', 'notes']),
                        'content_title' => $contract->content?->original_title,
                    ])->values(),
                    'fiscal_profiles' => $creator->fiscalProfiles->sortByDesc('effective_from')->map(fn ($profile) => $profile->only([
                        'id', 'person_type', 'tax_residency', 'is_vat_registered', 'vat_rate', 'withholding_enabled',
                        'withholding_rate', 'tax_identifier', 'iban', 'payment_currency', 'effective_from', 'effective_until', 'status',
                    ]))->values(),
                ])->values(),
            'contents' => \App\Models\Content::query()->orderBy('original_title')->get(['id', 'original_title'])->map(fn ($content) => ['id' => $content->id, 'title' => $content->original_title]),
            'settings' => ReportingSettingsVersion::query()->latest('effective_from')->get()->map(fn ($settings) => $settings->only(['id', 'domestic_country_code', 'domestic_vat_rate', 'effective_from', 'effective_until', 'is_active']))->values(),
        ]);
    }

    public function storeContract(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content_creator_id' => ['required', 'integer', 'exists:content_creators,id'],
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'share_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'territories' => ['nullable', 'array'], 'territories.*' => ['string', 'size:2'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'contract_reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $from = Carbon::parse($payload['effective_from'])->startOfDay();
        $until = isset($payload['effective_until']) ? Carbon::parse($payload['effective_until'])->endOfDay() : null;
        $contract = DB::transaction(function () use ($payload, $request, $from, $until) {
            $sameHolder = CreatorContractVersion::query()
                ->where('content_id', $payload['content_id'])
                ->where('content_creator_id', $payload['content_creator_id'])
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $until ?? Carbon::create(9999, 12, 31))
                ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from))
                ->lockForUpdate()->get();

            foreach ($sameHolder as $current) {
                if (Carbon::parse($current->effective_from)->startOfDay()->gte($from)) {
                    throw ValidationException::withMessages(['effective_from' => ['Data trebuie să fie după începutul versiunii contractuale existente.']]);
                }
                $current->update(['effective_until' => $from->copy()->subDay()->endOfDay()]);
            }

            $overlappingShare = CreatorContractVersion::query()->where('content_id', $payload['content_id'])->where('status', 'active')
                ->whereDate('effective_from', '<=', $until ?? Carbon::create(9999, 12, 31))
                ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from))
                ->lockForUpdate()->sum('share_percent');
            if ((float) $overlappingShare + (float) $payload['share_percent'] > 100.0001) {
                throw ValidationException::withMessages(['share_percent' => ['Cotele contractelor active suprapuse pentru acest film depășesc 100%.']]);
            }

            return CreatorContractVersion::query()->create([
                ...$payload, 'territories' => collect($payload['territories'] ?? [])->map(fn ($code) => strtoupper($code))->unique()->values()->all(),
                'status' => 'active', 'created_by' => $request->user()?->id,
            ]);
        });
        $this->auditLog->record('reporting.contract.created', 'creator_contract_version', $contract->id, $contract->toArray(), $request->user(), $request);

        return response()->json(['contract' => $contract], Response::HTTP_CREATED);
    }

    public function storeFiscalProfile(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content_creator_id' => ['required', 'integer', 'exists:content_creators,id'], 'person_type' => ['required', 'in:PF,PJ'],
            'tax_residency' => ['required', 'string', 'size:2'], 'is_vat_registered' => ['required', 'boolean'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'withholding_enabled' => ['required', 'boolean'],
            'withholding_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'tax_identifier' => ['nullable', 'string', 'max:32'],
            'iban' => ['nullable', 'string', 'max:64'], 'payment_currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date'], 'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);
        $from = Carbon::parse($payload['effective_from'])->startOfDay();
        $until = isset($payload['effective_until']) ? Carbon::parse($payload['effective_until'])->endOfDay() : null;
        $profile = DB::transaction(function () use ($payload, $request, $from, $until) {
            $overlapping = CreatorFiscalProfile::query()->where('content_creator_id', $payload['content_creator_id'])->where('status', 'active')
                ->whereDate('effective_from', '<=', $until ?? Carbon::create(9999, 12, 31))
                ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from))
                ->lockForUpdate()->get();
            foreach ($overlapping as $current) {
                if (Carbon::parse($current->effective_from)->startOfDay()->gte($from)) {
                    throw ValidationException::withMessages(['effective_from' => ['Data trebuie să fie după începutul profilului fiscal existent.']]);
                }
                $current->update(['effective_until' => $from->copy()->subDay()->endOfDay()]);
            }

            return CreatorFiscalProfile::query()->create([
                ...$payload, 'tax_residency' => strtoupper($payload['tax_residency']), 'payment_currency' => strtoupper($payload['payment_currency']),
                'vat_rate' => $payload['is_vat_registered'] ? $payload['vat_rate'] : 0,
                'withholding_rate' => $payload['withholding_enabled'] ? $payload['withholding_rate'] : 0,
                'status' => 'active', 'created_by' => $request->user()?->id,
            ]);
        });
        $this->auditLog->record('reporting.fiscal_profile.created', 'creator_fiscal_profile', $profile->id, ['content_creator_id' => $profile->content_creator_id], $request->user(), $request);

        return response()->json(['profile' => $profile], Response::HTTP_CREATED);
    }

    public function storeSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'domestic_country_code' => ['required', 'string', 'size:2'], 'domestic_vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
        ]);

        $settings = DB::transaction(function () use ($payload, $request) {
            $effectiveFrom = Carbon::parse($payload['effective_from'])->startOfDay();
            ReportingSettingsVersion::query()->where('is_active', true)->whereNull('effective_until')
                ->where('effective_from', '<', $effectiveFrom)->update(['effective_until' => $effectiveFrom->copy()->subSecond()]);

            return ReportingSettingsVersion::query()->create([
                ...$payload, 'domestic_country_code' => strtoupper($payload['domestic_country_code']), 'effective_from' => $effectiveFrom,
                'is_active' => true, 'created_by' => $request->user()?->id,
            ]);
        });
        $this->auditLog->record('reporting.settings.created', 'reporting_settings_version', $settings->id, $settings->toArray(), $request->user(), $request);

        return response()->json(['settings' => $settings], Response::HTTP_CREATED);
    }

    public function captureMissing(Request $request): JsonResponse
    {
        $captured = 0;
        ContentEntitlement::query()->whereDoesntHave('reportingSale')->with(['content', 'offer', 'user.defaultBillingAddress'])
            ->orderBy('id')->chunkById(100, function ($entitlements) use (&$captured): void {
                foreach ($entitlements as $entitlement) {
                    $this->reporting->capturePurchase($entitlement);
                    $captured++;
                }
            });
        $this->auditLog->record('reporting.capture_missing', 'reporting_sale', null, ['captured' => $captured], $request->user(), $request);

        return response()->json(['captured' => $captured]);
    }
}
