<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\ReportingSettingsVersion;
use App\Services\AuditLogService;
use App\Services\RightsReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
                ->with(['user:id,name,email', 'contents:id,original_title', 'contractVersions.content:id,original_title', 'fiscalProfiles'])
                ->orderBy('name')->get()->map(fn ($creator) => [
                    'id' => $creator->id, 'name' => $creator->name, 'company_name' => $creator->company_name,
                    'email' => $creator->email, 'is_active' => $creator->is_active,
                    'user' => $creator->user ? ['id' => $creator->user->id, 'name' => $creator->user->name, 'email' => $creator->user->email] : null,
                    'diagnostics' => $this->reporting->diagnostics($creator),
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
            'users' => \App\Models\User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email'])->values(),
            'settings' => ReportingSettingsVersion::query()->latest('effective_from')->get()->map(fn ($settings) => $settings->only(['id', 'domestic_country_code', 'domestic_vat_rate', 'effective_from', 'effective_until', 'is_active']))->values(),
        ]);
    }

    public function onboardHolder(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'content_creator_id' => ['nullable', 'integer', 'exists:content_creators,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:invite_email'],
            'invite_email' => ['nullable', 'email', 'max:255', 'required_without:user_id'],
            'invite_name' => ['nullable', 'string', 'max:255'],
            'content_ids' => ['required', 'array', 'min:1'],
            'content_ids.*' => ['integer', 'exists:contents,id'],
            'contract.share_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'contract.territories' => ['nullable', 'array'],
            'contract.territories.*' => ['string', 'size:2'],
            'contract.effective_from' => ['required', 'date'],
            'contract.effective_until' => ['nullable', 'date', 'after_or_equal:contract.effective_from'],
            'contract.contract_reference' => ['nullable', 'string', 'max:255'],
            'fiscal.person_type' => ['required', 'in:PF,PJ'],
            'fiscal.tax_residency' => ['required', 'string', 'size:2'],
            'fiscal.is_vat_registered' => ['required', 'boolean'],
            'fiscal.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'fiscal.withholding_enabled' => ['required', 'boolean'],
            'fiscal.withholding_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'fiscal.tax_identifier' => ['nullable', 'string', 'max:32'],
            'fiscal.iban' => ['nullable', 'string', 'max:64'],
            'fiscal.payment_currency' => ['required', 'string', 'size:3'],
            'fiscal.effective_from' => ['required', 'date'],
        ]);

        $creator = $this->reporting->onboardHolder($payload, $request->user());
        $this->auditLog->record('reporting.holder.onboarded', 'content_creator', $creator->id, [
            'user_id' => $creator->user_id, 'invite_email' => $payload['invite_email'] ?? null, 'content_ids' => $payload['content_ids'],
        ], $request->user(), $request);
        $this->reporting->recalculatePending();

        return response()->json([
            'creator' => $creator,
            'diagnostics' => $this->reporting->diagnostics($creator),
        ], Response::HTTP_CREATED);
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
        $contract = $this->reporting->createContractVersion($payload, $request->user()?->id);
        $this->auditLog->record('reporting.contract.created', 'creator_contract_version', $contract->id, $contract->toArray(), $request->user(), $request);
        // Any sale already stuck "missing_contract" for this titular/film gets repaired right away,
        // instead of waiting for the reporting:sync schedule.
        $this->reporting->recalculatePending();

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
        $profile = $this->reporting->createFiscalProfile($payload, $request->user()?->id);
        $this->auditLog->record('reporting.fiscal_profile.created', 'creator_fiscal_profile', $profile->id, ['content_creator_id' => $profile->content_creator_id], $request->user(), $request);
        $this->reporting->recalculatePending();

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

    /**
     * Manual/ops trigger for the same sync the reporting:sync schedule runs automatically
     * every few minutes (see routes/console.php). Not wired to any admin UI button — the
     * ledger is expected to keep itself current on its own.
     */
    public function captureMissing(Request $request): JsonResponse
    {
        $result = $this->reporting->syncAll();
        $this->auditLog->record('reporting.capture_missing', 'reporting_sale', null, $result, $request->user(), $request);

        return response()->json($result);
    }
}
