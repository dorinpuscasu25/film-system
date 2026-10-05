<?php

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\ContentCreator;
use App\Models\ContentEntitlement;
use App\Models\CreatorContractVersion;
use App\Models\CreatorFiscalProfile;
use App\Models\Invitation;
use App\Models\ReportingSale;
use App\Models\ReportingSettingsVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
                'calculation_status' => 'missing_contract',
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

            $this->applyAllocations($sale, $purchasedAt, $countryCode, $domesticCountry);

            return $sale->fresh('allocations');
        });
    }

    /**
     * (Re)computes holder allocations for a sale using the contracts/fiscal profiles valid
     * at its purchase date, and rolls the totals back up onto the sale row. Only ever called
     * for sales not yet at calculation_status "calculated" — once calculated a sale is frozen
     * for audit purposes and this is never invoked on it again.
     */
    protected function applyAllocations(ReportingSale $sale, CarbonInterface $purchasedAt, ?string $countryCode, ?string $domesticCountry = null): string
    {
        $vatAmount = (float) $sale->vat_amount;
        $netExVat = (float) $sale->net_ex_vat_amount;
        $domesticCountry ??= strtoupper((string) ($this->settingsFor($purchasedAt)?->domestic_country_code ?: 'MD'));

        $sale->allocations()->delete();

        $contracts = $this->contractsFor((int) $sale->content_id, $purchasedAt, $countryCode);
        $calculationStatus = $contracts->isEmpty() ? 'missing_contract' : 'calculated';
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
                'vat_rate' => $sale->vat_rate,
                'rounding' => 'half-up, 2 decimals',
                'reconciled_amount' => $this->money(max(0, $netExVat - $totals['base']) + max(0, $vatAmount - $totals['holder_vat']) + $totals['gross']),
            ],
        ])->save();

        return $calculationStatus;
    }

    /**
     * Repairs sales captured before their titular had a contract/fiscal profile set up.
     * Sales already at calculation_status "calculated" are never touched.
     *
     * @return array{repaired: int, still_pending: int}
     */
    public function recalculatePending(): array
    {
        $repaired = 0;
        $stillPending = 0;

        ReportingSale::query()
            ->whereIn('calculation_status', ['missing_contract', 'missing_fiscal_profile'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $sales) use (&$repaired, &$stillPending): void {
                foreach ($sales as $sale) {
                    DB::transaction(function () use ($sale, &$repaired, &$stillPending): void {
                        $status = $this->applyAllocations($sale, Carbon::parse($sale->purchased_at), $sale->country_code);
                        $status === 'calculated' ? $repaired++ : $stillPending++;
                    });
                }
            });

        return ['repaired' => $repaired, 'still_pending' => $stillPending];
    }

    /**
     * Full sync: captures any purchase that isn't in the ledger yet, then repairs anything
     * still stuck without a contract/fiscal profile. Called on a schedule (see routes/console.php)
     * so the reporting numbers keep themselves current without an admin having to trigger it.
     *
     * @return array{captured: int, repaired: int, still_pending: int}
     */
    public function syncAll(): array
    {
        $captured = 0;

        ContentEntitlement::query()->whereDoesntHave('reportingSale')->with(['content', 'offer', 'user.defaultBillingAddress'])
            ->orderBy('id')->chunkById(100, function (Collection $entitlements) use (&$captured): void {
                foreach ($entitlements as $entitlement) {
                    $this->capturePurchase($entitlement);
                    $captured++;
                }
            });

        return ['captured' => $captured, ...$this->recalculatePending()];
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

    public function createContractVersion(array $payload, ?int $createdBy): CreatorContractVersion
    {
        $from = Carbon::parse($payload['effective_from'])->startOfDay();
        $until = isset($payload['effective_until']) ? Carbon::parse($payload['effective_until'])->endOfDay() : null;

        return DB::transaction(function () use ($payload, $createdBy, $from, $until) {
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
                'status' => 'active', 'created_by' => $createdBy,
            ]);
        });
    }

    public function createFiscalProfile(array $payload, ?int $createdBy): CreatorFiscalProfile
    {
        $from = Carbon::parse($payload['effective_from'])->startOfDay();
        $until = isset($payload['effective_until']) ? Carbon::parse($payload['effective_until'])->endOfDay() : null;

        return DB::transaction(function () use ($payload, $createdBy, $from, $until) {
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
                'status' => 'active', 'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * One-shot setup for a titular: links (or invites) a login user, grants it the access it
     * needs to see its own reporting, and creates the contract + fiscal profile versions.
     * Fixes the previous multi-screen flow where those pieces were easy to leave out of sync
     * with each other, which is what made a titular's numbers look wrong or come out at zero.
     */
    public function onboardHolder(array $payload, User $actor): ContentCreator
    {
        return DB::transaction(function () use ($payload, $actor): ContentCreator {
            $user = isset($payload['user_id']) ? User::query()->findOrFail($payload['user_id']) : null;

            $creator = isset($payload['content_creator_id'])
                ? ContentCreator::query()->findOrFail($payload['content_creator_id'])
                : new ContentCreator();

            $creator->fill([
                'name' => $payload['name'],
                'email' => $payload['email'] ?? $user?->email ?? ($payload['invite_email'] ?? $creator->email),
                'company_name' => $payload['company_name'] ?? $creator->company_name,
                'is_active' => true,
            ]);
            if ($user !== null) {
                $creator->user_id = $user->id;
            }
            $creator->save();

            $creator->contents()->sync(
                collect($payload['content_ids'])
                    ->map(fn ($id) => (int) $id)
                    ->mapWithKeys(fn (int $id) => [$id => ['role' => 'owner', 'is_primary' => false]])
                    ->all(),
            );

            if ($user !== null) {
                $this->ensureHolderAccess($user);
            } elseif (! empty($payload['invite_email'])) {
                $this->sendHolderInvite($payload['invite_email'], $payload['invite_name'] ?? $payload['name'], $payload['content_ids'], $actor);
            }

            foreach ($payload['content_ids'] as $contentId) {
                $this->createContractVersion([
                    'content_creator_id' => $creator->id,
                    'content_id' => (int) $contentId,
                    'share_percent' => $payload['contract']['share_percent'],
                    'territories' => $payload['contract']['territories'] ?? [],
                    'effective_from' => $payload['contract']['effective_from'],
                    'effective_until' => $payload['contract']['effective_until'] ?? null,
                    'contract_reference' => $payload['contract']['contract_reference'] ?? null,
                    'notes' => $payload['contract']['notes'] ?? null,
                ], $actor->id);
            }

            $this->createFiscalProfile([
                'content_creator_id' => $creator->id,
                ...$payload['fiscal'],
            ], $actor->id);

            return $creator->fresh(['user', 'contents', 'contractVersions', 'fiscalProfiles']);
        });
    }

    /**
     * Grants the Producer role (scoped admin access + financial visibility) without touching
     * any role the user already has — merges, never overwrites, existing role assignments.
     */
    protected function ensureHolderAccess(User $user): void
    {
        $user->loadMissing('roles.permissions');
        if ($user->hasAdminPanelAccess() && $user->hasPermission('content.scope_assigned') && $user->hasPermission('content.view_financials')) {
            return;
        }

        $producerRoleId = Role::query()->where('name', 'Producer')->value('id');
        if ($producerRoleId === null) {
            return;
        }

        $currentRoleIds = $user->roles->pluck('id')->all();
        if (! in_array($producerRoleId, $currentRoleIds, true)) {
            $user->syncRoleIds([...$currentRoleIds, $producerRoleId]);
        }
    }

    /**
     * Invites a not-yet-existing titular with the Producer role and the selected films
     * pre-assigned. InvitationController::accept links the ContentCreator back to the new
     * user by matching this email once they accept.
     */
    protected function sendHolderInvite(string $email, ?string $name, array $contentIds, User $actor): void
    {
        $producerRoleId = Role::query()->where('name', 'Producer')->value('id');
        if ($producerRoleId === null) {
            return;
        }

        $plainTextToken = Str::random(64);
        $invitation = Invitation::query()->create([
            'email' => strtolower($email),
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'role_ids' => [$producerRoleId],
            'assigned_content_ids' => collect($contentIds)->map(fn ($id) => (int) $id)->unique()->values()->all(),
            'status' => 'pending',
            'invited_by' => $actor->id,
            'expires_at' => now()->addHours(72),
        ]);

        $acceptUrl = rtrim((string) env('ADMIN_FRONTEND_URL', 'http://localhost:5174'), '/').'/accept-invite?token='.$plainTextToken;

        Mail::to($invitation->email)->send(new UserInvitationMail(
            appName: config('app.name'),
            inviteeName: $invitation->name,
            inviterName: $actor->name,
            acceptUrl: $acceptUrl,
            expiresAt: $invitation->expires_at,
            roleNames: ['Producer'],
        ));
    }

    /**
     * Setup issues for a titular card in the admin UI, so a broken link between the user's
     * access, the creator record and its contract/fiscal versions is visible immediately
     * instead of silently producing zeroed-out or missing numbers.
     *
     * @return list<string>
     */
    public function diagnostics(ContentCreator $creator): array
    {
        $issues = [];

        if (! $creator->is_active) {
            $issues[] = 'creator_inactive';
        }

        if ($creator->user_id === null) {
            $issues[] = $creator->email && Invitation::query()->where('email', strtolower($creator->email))->where('status', 'pending')->exists()
                ? 'pending_invitation'
                : 'no_user';
        } else {
            $user = $creator->relationLoaded('user') ? $creator->user : User::find($creator->user_id);
            if ($user === null) {
                $issues[] = 'no_user';
            } else {
                $user->loadMissing('roles.permissions');
                if (! $user->hasAdminPanelAccess() || ! $user->hasPermission('content.scope_assigned') || ! $user->hasPermission('content.view_financials')) {
                    $issues[] = 'missing_role_access';
                }
            }
        }

        $contracts = $creator->relationLoaded('contractVersions') ? $creator->contractVersions : $creator->contractVersions()->get();
        if (! $contracts->contains(fn (CreatorContractVersion $c) => $this->isCurrentlyActive($c))) {
            $issues[] = 'no_active_contract';
        }

        $fiscalProfiles = $creator->relationLoaded('fiscalProfiles') ? $creator->fiscalProfiles : $creator->fiscalProfiles()->get();
        if (! $fiscalProfiles->contains(fn (CreatorFiscalProfile $p) => $this->isCurrentlyActive($p))) {
            $issues[] = 'no_fiscal_profile';
        }

        return $issues;
    }

    protected function isCurrentlyActive(CreatorContractVersion|CreatorFiscalProfile $version): bool
    {
        if ($version->status !== 'active') {
            return false;
        }
        $today = Carbon::now()->startOfDay();
        if (Carbon::parse($version->effective_from)->gt($today)) {
            return false;
        }
        if ($version->effective_until !== null && Carbon::parse($version->effective_until)->lt($today)) {
            return false;
        }

        return true;
    }
}
