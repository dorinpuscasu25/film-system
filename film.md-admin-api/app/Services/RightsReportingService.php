<?php

namespace App\Services;

use App\Models\ContentEntitlement;
use App\Models\CreatorContractVersion;
use App\Models\CreatorFiscalProfile;
use App\Models\ReportingSale;
use App\Models\ReportingSettingsVersion;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonInterface;

class RightsReportingService
{
    public const CALCULATION_VERSION = 'v1';

    public function __construct(protected ContentScopeService $contentScope) {}

    public function capturePurchase(ContentEntitlement $entitlement, ?WalletTransaction $walletTransaction = null): ReportingSale
    {
        $entitlement->loadMissing(['content', 'offer', 'user.defaultBillingAddress']);

        return DB::transaction(function () use ($entitlement, $walletTransaction): ReportingSale {
            $existing = ReportingSale::query()->where('content_entitlement_id', $entitlement->id)->first();
            if ($existing !== null) {
                return $existing->load('allocations');
            }

            $purchasedAt = $entitlement->granted_at ?? $entitlement->created_at ?? now();
            $countryCode = $this->resolveCountryCode($entitlement);
            $settings = $this->settingsFor($purchasedAt);
            $domesticCountry = strtoupper((string) ($settings?->domestic_country_code ?: 'MD'));
            $market = $countryCode === $domesticCountry ? 'domestic' : 'export';
            $vatRate = $market === 'domestic' ? (float) ($settings?->domestic_vat_rate ?? 20) : 0.0;
            $gross = $this->money((float) $entitlement->price_amount);
            $vatAmount = $vatRate > 0 ? $this->money($gross - ($gross / (1 + $vatRate / 100))) : 0.0;
            $netExVat = $this->money($gross - $vatAmount);
            $contracts = $this->contractsFor((int) $entitlement->content_id, $purchasedAt, $countryCode);
            $calculationStatus = $contracts->isEmpty() ? 'missing_contract' : 'calculated';

            $sale = ReportingSale::query()->create([
                'uuid' => (string) Str::uuid(),
                'content_entitlement_id' => $entitlement->id,
                'wallet_transaction_id' => $walletTransaction?->id,
                'buyer_user_id' => $entitlement->user_id,
                'content_id' => $entitlement->content_id,
                'offer_id' => $entitlement->offer_id,
                'settings_version_id' => $settings?->id,
                'purchased_at' => $purchasedAt,
                'status' => 'completed',
                'calculation_status' => $calculationStatus,
                'calculation_version' => self::CALCULATION_VERSION,
                'country_code' => $countryCode,
                'market' => $market,
                'sales_channel' => (string) Arr::get($entitlement->meta ?? [], 'sales_channel', 'web'),
                'payment_method' => (string) Arr::get($walletTransaction?->meta ?? [], 'payment_method', 'wallet'),
                'payment_processor' => Arr::get($walletTransaction?->meta ?? [], 'payment_processor', 'Filmoteca Wallet'),
                'currency' => $entitlement->currency ?: 'MDL',
                'content_title' => $entitlement->content?->original_title ?: 'Film șters',
                'offer_name' => $entitlement->offer?->name ?: Arr::get($entitlement->meta ?? [], 'offer_name'),
                'quality' => $entitlement->quality,
                'rental_days' => $entitlement->offer?->rental_days,
                'gross_amount' => $gross,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'net_ex_vat_amount' => $netExVat,
                'platform_share_amount' => $netExVat,
                'platform_vat_amount' => $vatAmount,
                'source_snapshot' => [
                    'content_id' => $entitlement->content_id,
                    'content_title' => $entitlement->content?->original_title,
                    'offer_id' => $entitlement->offer_id,
                    'offer_name' => $entitlement->offer?->name,
                    'offer_type' => $entitlement->access_type,
                    'quality' => $entitlement->quality,
                    'rental_days' => $entitlement->offer?->rental_days,
                    'access_location' => $entitlement->access_location,
                    'wallet_funding' => $walletTransaction?->meta,
                ],
            ]);

            $totals = ['base' => 0.0, 'holder_vat' => 0.0, 'gross' => 0.0, 'withholding' => 0.0, 'net' => 0.0];

            foreach ($contracts as $contract) {
                $fiscal = $this->fiscalProfileFor((int) $contract->content_creator_id, $purchasedAt);
                if ($fiscal === null) {
                    $calculationStatus = 'missing_fiscal_profile';
                }

                $shareRatio = (float) $contract->share_percent / 100;
                $baseShare = $this->money($netExVat * $shareRatio);
                $holderVat = $fiscal?->is_vat_registered ? $this->money($vatAmount * $shareRatio) : 0.0;
                $grossShare = $this->money($baseShare + $holderVat);
                $withholdingRate = $fiscal?->withholding_enabled ? (float) $fiscal->withholding_rate : 0.0;
                $withholding = $this->money($baseShare * $withholdingRate / 100);
                $netPayable = $this->money($grossShare - $withholding);

                $sale->allocations()->create([
                    'content_creator_id' => $contract->content_creator_id,
                    'contract_version_id' => $contract->id,
                    'fiscal_profile_id' => $fiscal?->id,
                    'holder_name' => $contract->creator?->name ?: 'Titular șters',
                    'share_percent' => $contract->share_percent,
                    'base_share_amount' => $baseShare,
                    'vat_amount' => $holderVat,
                    'gross_share_amount' => $grossShare,
                    'withholding_rate' => $withholdingRate,
                    'withholding_amount' => $withholding,
                    'net_payable_amount' => $netPayable,
                    'person_type' => $fiscal?->person_type,
                    'is_vat_registered' => (bool) $fiscal?->is_vat_registered,
                    'contract_snapshot' => $contract->only(['id', 'content_creator_id', 'content_id', 'share_percent', 'territories', 'effective_from', 'effective_until', 'contract_reference']),
                    'fiscal_snapshot' => $fiscal?->only(['id', 'person_type', 'tax_residency', 'is_vat_registered', 'vat_rate', 'withholding_enabled', 'withholding_rate', 'tax_identifier', 'iban', 'payment_currency', 'effective_from', 'effective_until']),
                ]);

                $totals['base'] += $baseShare;
                $totals['holder_vat'] += $holderVat;
                $totals['gross'] += $grossShare;
                $totals['withholding'] += $withholding;
                $totals['net'] += $netPayable;
            }

            $sale->forceFill([
                'calculation_status' => $calculationStatus,
                'holders_gross_amount' => $this->money($totals['gross']),
                'holders_vat_amount' => $this->money($totals['holder_vat']),
                'withholding_amount' => $this->money($totals['withholding']),
                'holders_net_amount' => $this->money($totals['net']),
                'platform_share_amount' => $this->money(max(0, $netExVat - $totals['base'])),
                'platform_vat_amount' => $this->money(max(0, $vatAmount - $totals['holder_vat'])),
                'calculation_snapshot' => [
                    'version' => self::CALCULATION_VERSION,
                    'formula' => 'gross = net_ex_vat + vat; holder_base = net_ex_vat × contract_share; holder_vat = vat × share only for VAT holder; withholding = holder_base × rate',
                    'domestic_country_code' => $domesticCountry,
                    'vat_rate' => $vatRate,
                    'rounding' => 'half-up, 2 decimals',
                    'reconciled_amount' => $this->money(max(0, $netExVat - $totals['base']) + max(0, $vatAmount - $totals['holder_vat']) + $totals['gross']),
                ],
            ])->save();

            return $sale->load('allocations');
        });
    }

    public function dashboard(User $user, array $filters): array
    {
        $query = $this->filteredSalesQuery($user, $filters)->with('allocations');
        $sales = $query->orderBy('purchased_at')->get();
        $visibleCreatorIds = $this->visibleCreatorIds($user);

        if ($visibleCreatorIds !== null) {
            $sales->each(fn (ReportingSale $sale) => $sale->setRelation(
                'allocations',
                $sale->allocations->whereIn('content_creator_id', $visibleCreatorIds)->values(),
            ));
        }

        $summary = $this->summary($sales, $visibleCreatorIds !== null);
        $byFilm = $sales->groupBy('content_id')->map(function (Collection $rows): array {
            $first = $rows->first();
            return [
                'content_id' => $first?->content_id,
                'title' => $first?->content_title,
                'purchases' => $rows->where('gross_amount', '>', 0)->count(),
                'gross_amount' => $this->money($rows->sum('gross_amount')),
                'holder_gross_amount' => $this->money($rows->sum(fn (ReportingSale $s) => $s->allocations->sum('gross_share_amount'))),
                'withholding_amount' => $this->money($rows->sum(fn (ReportingSale $s) => $s->allocations->sum('withholding_amount'))),
                'net_payable_amount' => $this->money($rows->sum(fn (ReportingSale $s) => $s->allocations->sum('net_payable_amount'))),
            ];
        })->sortByDesc('gross_amount')->values();

        return [
            'scope' => ['is_holder' => $visibleCreatorIds !== null, 'creator_ids' => $visibleCreatorIds ?? []],
            'summary' => $summary,
            'by_film' => $byFilm,
            'timeline' => $this->dimension($sales, fn (ReportingSale $s) => $s->purchased_at?->format('Y-m-d') ?? 'N/A'),
            'countries' => $this->dimension($sales, fn (ReportingSale $s) => $s->country_code ?: 'N/A'),
            'qualities' => $this->dimension($sales, fn (ReportingSale $s) => $s->quality ?: 'N/A'),
            'durations' => $this->dimension($sales, fn (ReportingSale $s) => $s->rental_days ? $s->rental_days.' zile' : 'Permanent'),
            'channels' => $this->dimension($sales, fn (ReportingSale $s) => $s->sales_channel ?: 'N/A'),
            'payment_methods' => $this->dimension($sales, fn (ReportingSale $s) => $s->payment_method ?: 'N/A'),
            'transactions' => $sales->sortByDesc('purchased_at')->take(100)->map(fn (ReportingSale $sale) => $this->saleData($sale))->values(),
            'options' => [
                'contents' => $byFilm->map(fn (array $row) => ['id' => $row['content_id'], 'title' => $row['title']])->values(),
                'countries' => $sales->pluck('country_code')->filter()->unique()->sort()->values(),
            ],
        ];
    }

    public function exportRows(User $user, array $filters): Collection
    {
        $visibleCreatorIds = $this->visibleCreatorIds($user);

        return $this->filteredSalesQuery($user, $filters)
            ->with('allocations')
            ->orderBy('purchased_at')
            ->get()
            ->flatMap(function (ReportingSale $sale) use ($visibleCreatorIds): Collection {
                $allocations = $sale->allocations;
                if ($visibleCreatorIds !== null) {
                    $allocations = $allocations->whereIn('content_creator_id', $visibleCreatorIds);
                }
                if ($allocations->isEmpty()) {
                    return collect([$this->exportRow($sale, null)]);
                }

                return $allocations->values()->map(fn ($allocation, int $index) => $this->exportRow($sale, $allocation, $index === 0));
            })->values();
    }

    public function settingsFor(CarbonInterface $at): ?ReportingSettingsVersion
    {
        return ReportingSettingsVersion::query()->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at))
            ->latest('effective_from')->first();
    }

    protected function contractsFor(int $contentId, CarbonInterface $at, ?string $countryCode): Collection
    {
        return CreatorContractVersion::query()->with('creator')->where('content_id', $contentId)->where('status', 'active')
            ->whereDate('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $at))
            ->get()->filter(function (CreatorContractVersion $contract) use ($countryCode): bool {
                $territories = collect($contract->territories)->map(fn ($value) => strtoupper((string) $value))->filter();
                return $territories->isEmpty() || ($countryCode !== null && $territories->contains(strtoupper($countryCode)));
            })->values();
    }

    protected function fiscalProfileFor(int $creatorId, CarbonInterface $at): ?CreatorFiscalProfile
    {
        return CreatorFiscalProfile::query()->where('content_creator_id', $creatorId)->where('status', 'active')
            ->whereDate('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $at))
            ->latest('effective_from')->first();
    }

    protected function filteredSalesQuery(User $user, array $filters): Builder
    {
        $query = ReportingSale::query();
        $this->contentScope->scopeContentQuery($user, $query, 'reporting_sales.content_id');
        $query->when(Arr::get($filters, 'from'), fn (Builder $q, $date) => $q->where('purchased_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when(Arr::get($filters, 'to'), fn (Builder $q, $date) => $q->where('purchased_at', '<=', Carbon::parse($date)->endOfDay()))
            ->when(Arr::get($filters, 'content_id'), fn (Builder $q, $id) => $q->where('content_id', (int) $id))
            ->when(Arr::get($filters, 'country_code'), fn (Builder $q, $code) => $q->where('country_code', strtoupper((string) $code)))
            ->when(Arr::get($filters, 'market'), fn (Builder $q, $market) => $market !== 'all' ? $q->where('market', $market) : $q)
            ->when(Arr::get($filters, 'quality'), fn (Builder $q, $quality) => $q->where('quality', $quality));

        return $query;
    }

    protected function visibleCreatorIds(User $user): ?array
    {
        if (! $this->contentScope->isScoped($user)) {
            return null;
        }

        return $user->relationLoaded('roles') || $user->exists
            ? \App\Models\ContentCreator::query()->where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    protected function summary(Collection $sales, bool $holderView): array
    {
        $allocationSum = fn (string $field): float => (float) $sales->sum(fn (ReportingSale $s) => $s->allocations->sum($field));

        return [
            'purchases' => $sales->where('gross_amount', '>', 0)->count(),
            'gross_amount' => $this->money($sales->sum('gross_amount')),
            'vat_amount' => $this->money($sales->sum('vat_amount')),
            'net_ex_vat_amount' => $this->money($sales->sum('net_ex_vat_amount')),
            'holder_gross_amount' => $this->money($allocationSum('gross_share_amount')),
            'withholding_amount' => $this->money($allocationSum('withholding_amount')),
            'holder_net_amount' => $this->money($allocationSum('net_payable_amount')),
            'platform_share_amount' => $this->money($holderView ? 0 : $sales->sum('platform_share_amount')),
            'refund_amount' => $this->money($sales->sum('refund_amount')),
            'domestic_amount' => $this->money($sales->where('market', 'domestic')->sum('gross_amount')),
            'export_amount' => $this->money($sales->where('market', 'export')->sum('gross_amount')),
            'needs_review' => $sales->where('calculation_status', '!=', 'calculated')->count(),
            'currency' => $sales->first()?->currency ?? 'MDL',
        ];
    }

    protected function dimension(Collection $sales, callable $key): Collection
    {
        return $sales->groupBy($key)->map(fn (Collection $rows, string $label) => [
            'label' => $label,
            'purchases' => $rows->where('gross_amount', '>', 0)->count(),
            'amount' => $this->money($rows->sum('gross_amount')),
        ])->sortByDesc('amount')->values();
    }

    protected function saleData(ReportingSale $sale): array
    {
        return [
            'id' => $sale->uuid, 'purchased_at' => $sale->purchased_at?->toIso8601String(), 'content_id' => $sale->content_id,
            'film' => $sale->content_title, 'country_code' => $sale->country_code, 'market' => $sale->market,
            'offer' => $sale->offer_name, 'quality' => $sale->quality, 'rental_days' => $sale->rental_days,
            'gross_amount' => $sale->gross_amount, 'vat_amount' => $sale->vat_amount, 'net_ex_vat_amount' => $sale->net_ex_vat_amount,
            'platform_share_amount' => $sale->platform_share_amount, 'payment_method' => $sale->payment_method,
            'sales_channel' => $sale->sales_channel, 'currency' => $sale->currency, 'calculation_status' => $sale->calculation_status,
            'allocations' => $sale->allocations->map(fn ($a) => [
                'holder' => $a->holder_name, 'share_percent' => $a->share_percent, 'person_type' => $a->person_type,
                'is_vat_registered' => $a->is_vat_registered, 'gross_share_amount' => $a->gross_share_amount,
                'withholding_amount' => $a->withholding_amount, 'net_payable_amount' => $a->net_payable_amount,
            ])->values(),
        ];
    }

    protected function exportRow(ReportingSale $sale, mixed $allocation, bool $includeSaleAmounts = true): array
    {
        return [
            'data' => $sale->purchased_at?->format('Y-m-d H:i:s'), 'id_tranzactie' => $sale->uuid, 'film' => $sale->content_title,
            'titular' => $allocation?->holder_name, 'tara' => $sale->country_code, 'piata' => strtoupper($sale->market),
            'oferta' => $sale->offer_name, 'calitate' => $sale->quality, 'durata_zile' => $sale->rental_days,
            'suma_achitata' => $includeSaleAmounts ? $sale->gross_amount : 0, 'tva_vanzare' => $includeSaleAmounts ? $sale->vat_amount : 0,
            'suma_fara_tva' => $includeSaleAmounts ? $sale->net_ex_vat_amount : 0,
            'cota_609_film' => $includeSaleAmounts ? $sale->platform_share_amount : 0, 'cota_titular' => $allocation?->gross_share_amount ?? 0,
            'tip_persoana' => $allocation?->person_type, 'statut_tva' => $allocation ? ($allocation->is_vat_registered ? 'TVA' : 'NTVA') : null,
            'retinere_la_sursa' => $allocation?->withholding_amount ?? 0, 'net_titular' => $allocation?->net_payable_amount ?? 0,
            'procesator_plata' => $sale->payment_processor, 'metoda_plata' => $sale->payment_method,
            'refund' => $includeSaleAmounts ? $sale->refund_amount : 0, 'status_calcul' => $sale->calculation_status, 'versiune_calcul' => $sale->calculation_version,
        ];
    }

    protected function resolveCountryCode(ContentEntitlement $entitlement): ?string
    {
        if ($entitlement->access_location === ContentEntitlement::ACCESS_LOCATION_MOLDOVA) return 'MD';
        $billingCountry = $entitlement->user?->defaultBillingAddress?->country_code;
        if ($billingCountry) return strtoupper((string) $billingCountry);
        if ($entitlement->access_location === ContentEntitlement::ACCESS_LOCATION_OUTSIDE_MOLDOVA) return 'XX';
        return null;
    }

    protected function money(float $value): float { return round($value, 2, PHP_ROUND_HALF_UP); }
}
