<?php

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\ContentCreator;
use App\Models\ContentEntitlement;
use App\Models\CreatorContractVersion;
use App\Models\CreatorFiscalProfile;
use App\Models\Invitation;
use App\Models\Offer;
use App\Models\ReportingSale;
use App\Models\ReportingSettingsVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletFundingAllocation;
use App\Models\WalletFundingLot;
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
    /** v2: contract cap. 6 — base from money actually collected (funding lots), store commission and conversion cost deducted, promotional credit excluded. */
    public const CALCULATION_VERSION = 'v2';

    public function __construct(
        protected ContentScopeService $contentScope,
        protected WalletFundingLedger $fundingLedger,
    ) {}

    public function capturePurchase(ContentEntitlement $entitlement, ?WalletTransaction $walletTransaction = null): ReportingSale
    {
        $entitlement->loadMissing(['content', 'offer', 'user.defaultBillingAddress']);

        return DB::transaction(function () use ($entitlement, $walletTransaction): ReportingSale {
            $existing = ReportingSale::query()->where('content_entitlement_id', $entitlement->id)->first();
            if ($existing !== null) {
                return $existing->load('allocations');
            }
            $walletTransaction ??= $this->purchaseTransactionFor($entitlement);

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

            $this->recordFundings($sale, $walletTransaction, $purchasedAt);
            $this->applyAllocations($sale, $purchasedAt, $countryCode, $domesticCountry);

            return $sale->fresh(['allocations', 'fundings']);
        });
    }

    /**
     * What paid for the sale, valued in real money (contract 6.4–6.7): the funding lots its wallet
     * debit consumed. Credit spent before the ledger existed (no lot) is valued from the debit's
     * per-source split; a sale with no wallet debit at all is taken as card money.
     */
    public function recordFundings(ReportingSale $sale, ?WalletTransaction $walletTransaction, CarbonInterface $at): void
    {
        $sale->fundings()->delete();
        $credits = round((float) $sale->gross_amount, 2);
        $rows = collect();

        if ($credits > 0 && $walletTransaction !== null) {
            $allocations = WalletFundingAllocation::query()->with('lot')->where('debit_transaction_id', $walletTransaction->id)->get();
            foreach ($allocations as $allocation) {
                if ($allocation->lot === null) {
                    continue;
                }
                $rows->push(['lot_id' => $allocation->lot_id, 'source' => $allocation->lot->source, 'credits' => (float) $allocation->amount,
                    'value' => $allocation->lot->valueOf((float) $allocation->amount), 'status' => $allocation->lot->valuation_status]);
            }

            $meta = $walletTransaction->meta ?? [];
            $hasSplit = array_key_exists('own_amount', $meta) || array_key_exists('platform_amount', $meta) || array_key_exists('apple_amount', $meta);
            foreach (WalletFundingLot::SPEND_ORDER as $source) {
                $declared = $hasSplit ? round((float) ($meta["{$source}_amount"] ?? 0), 2) : ($source === WalletFundingLot::SOURCE_OWN ? $credits : 0.0);
                $missing = round($declared - $rows->where('source', $source)->sum('credits'), 2);
                if ($missing > 0) {
                    $rows->push($this->unledgeredFunding($source, $missing, $at));
                }
            }
        } elseif ($credits > 0) {
            $rows->push($this->unledgeredFunding(WalletFundingLot::SOURCE_OWN, $credits, $at));
        }

        foreach ($rows as $row) {
            $sale->fundings()->create([
                'lot_id' => $row['lot_id'], 'source' => $row['source'], 'credits' => $row['credits'],
                'gross_value' => $row['value']['gross'], 'commission_value' => $row['value']['commission'],
                'fx_cost_value' => $row['value']['fx_cost'], 'net_value' => $row['value']['net'], 'valuation_status' => $row['status'],
            ]);
        }

        $bySource = fn (string $source): float => round((float) $rows->where('source', $source)->sum('credits'), 2);
        $commission = $this->money((float) $rows->sum(fn ($row) => $row['value']['commission']));
        $fxCost = $this->money((float) $rows->sum(fn ($row) => $row['value']['fx_cost']));
        $sale->forceFill([
            'promo_credits' => $bySource(WalletFundingLot::SOURCE_PLATFORM),
            'own_credits' => $bySource(WalletFundingLot::SOURCE_OWN),
            'apple_credits' => $bySource(WalletFundingLot::SOURCE_APPLE),
            'revenue_gross_amount' => $this->money((float) $rows->sum(fn ($row) => $row['value']['gross'])),
            'channel_commission_amount' => $commission,
            'fx_cost_amount' => $fxCost,
            'deductions_amount' => $this->money($commission + $fxCost + (float) $sale->refund_amount),
            'valuation_status' => $rows->contains(fn ($row) => $row['status'] === WalletFundingLot::VALUATION_ESTIMATED)
                ? WalletFundingLot::VALUATION_ESTIMATED : WalletFundingLot::VALUATION_FINAL,
            'payment_method' => $this->paymentMethodLabel($rows),
        ])->save();
    }

    /** @return array{lot_id: null, source: string, credits: float, value: array, status: string} */
    protected function unledgeredFunding(string $source, float $credits, CarbonInterface $at): array
    {
        $valuation = $this->fundingLedger->valuation($source, $credits, null, $at);

        return [
            'lot_id' => null, 'source' => $source, 'credits' => $credits, 'status' => $valuation['valuation_status'],
            'value' => ['gross' => (float) $valuation['gross_value'], 'commission' => (float) $valuation['commission_value'],
                'fx_cost' => (float) $valuation['fx_cost_value'], 'net' => (float) $valuation['net_value']],
        ];
    }

    protected function paymentMethodLabel(Collection $rows): string
    {
        $sources = $rows->where('credits', '>', 0)->pluck('source')->unique()->values();

        return match (true) {
            $sources->isEmpty() => 'free',
            $sources->count() > 1 => 'mixed',
            default => match ($sources->first()) {
                WalletFundingLot::SOURCE_APPLE => 'app_store',
                WalletFundingLot::SOURCE_PLATFORM => 'promotional',
                default => 'card_maib',
            },
        };
    }

    /** Re-runs the contract calculation for a sale (after its fundings were rebuilt or revalued). */
    public function recalculate(ReportingSale $sale): string
    {
        return $this->applyAllocations($sale->fresh(), Carbon::parse($sale->purchased_at), $sale->country_code);
    }

    public function purchaseTransactionForSale(ReportingSale $sale): ?WalletTransaction
    {
        $entitlement = $sale->entitlement()->first();

        return $entitlement !== null ? $this->purchaseTransactionFor($entitlement) : null;
    }

    /** The wallet debit of a purchase captured after the fact (it isn't linked to the entitlement). */
    protected function purchaseTransactionFor(ContentEntitlement $entitlement): ?WalletTransaction
    {
        if ((float) $entitlement->price_amount <= 0 || $entitlement->offer_id === null) {
            return null;
        }
        $at = Carbon::parse($entitlement->granted_at ?? $entitlement->created_at ?? now());

        return WalletTransaction::query()
            ->where('user_id', $entitlement->user_id)
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('reference_type', (new Offer)->getMorphClass())
            ->where('reference_id', $entitlement->offer_id)
            ->where('amount', -round((float) $entitlement->price_amount, 2))
            ->whereBetween('processed_at', [$at->copy()->subMinutes(10), $at->copy()->addMinutes(10)])
            ->orderBy('id')
            ->first();
    }

    /**
     * (Re)computes holder allocations for a sale using the contracts/fiscal profiles valid
     * at its purchase date, and rolls the totals back up onto the sale row. Only ever called
     * for sales not yet at calculation_status "calculated" — once calculated a sale is frozen
     * for audit purposes and this is never invoked on it again.
     */
    /**
     * Contract cap. 6, per sale and titular:
     *   Venit Brut           = money actually paid for the sale (card + App Store, promo excluded)
     *   Deduceri (6.5)       = store commission + conversion cost (+ refunds)
     *   Bază de Repartizare  = Venit Brut − Deduceri                      (VAT-registered titular, 6.15; export, 6.19)
     *                        = Venit Brut − TVA colectată − Deduceri      (non-registered titular, 6.17 / 6.18)
     *   Remunerația totală   = Bază × cota; for a VAT-registered titular it includes the VAT of its invoice (6.15, 6.20)
     *   Reținere (6.32)      = 12% (or the profile's rate) of the remuneration before VAT, persons only
     *   Of the net, the part funded by App Store packs not yet settled is "în curs de decontare" (6.29).
     */
    protected function applyAllocations(ReportingSale $sale, CarbonInterface $purchasedAt, ?string $countryCode, ?string $domesticCountry = null): string
    {
        $settings = $this->settingsFor($purchasedAt);
        $domesticCountry ??= strtoupper((string) ($settings?->domestic_country_code ?: 'MD'));
        $sale->loadMissing('fundings');

        $price = (float) $sale->gross_amount;
        $paidCredits = (float) $sale->own_credits + (float) $sale->apple_credits;
        $vatOnPaid = $price > 0 ? $this->money((float) $sale->vat_amount * min(1, $paidCredits / $price)) : 0.0;
        $revenue = (float) $sale->revenue_gross_amount;
        $deductions = (float) $sale->deductions_amount;
        $netCollectable = (float) $sale->fundings->sum('net_value');
        $pendingNet = (float) $sale->fundings
            ->where('source', WalletFundingLot::SOURCE_APPLE)
            ->where('valuation_status', WalletFundingLot::VALUATION_ESTIMATED)
            ->sum('net_value');
        $pendingRatio = $netCollectable > 0 ? min(1, $pendingNet / $netCollectable) : 0.0;
        $defaultVatRate = (float) ($settings?->domestic_vat_rate ?? 20);

        $sale->allocations()->delete();

        $contracts = $this->contractsFor((int) $sale->content_id, $purchasedAt, $countryCode);
        $calculationStatus = $contracts->isEmpty() ? 'missing_contract' : 'calculated';
        $totals = ['base_share' => 0.0, 'holder_vat' => 0.0, 'gross' => 0.0, 'withholding' => 0.0, 'net' => 0.0];
        $distributionBase = $this->money(max(0, $revenue - $vatOnPaid - $deductions));

        foreach ($contracts as $contract) {
            $fiscal = $this->fiscalProfileFor((int) $contract->content_creator_id, $purchasedAt);
            if ($fiscal === null) {
                $calculationStatus = 'missing_fiscal_profile';
            }

            $vatHolder = (bool) $fiscal?->is_vat_registered;
            $base = $this->money(max(0, $vatHolder ? $revenue - $deductions : $revenue - $vatOnPaid - $deductions));
            $distributionBase = $base;
            $shareRatio = (float) $contract->share_percent / 100;
            $holderTotal = $this->money($base * $shareRatio);
            $holderVatRate = $vatHolder ? ((float) $fiscal->vat_rate > 0 ? (float) $fiscal->vat_rate : $defaultVatRate) : 0.0;
            $holderVat = $holderVatRate > 0 ? $this->money($holderTotal * $holderVatRate / (100 + $holderVatRate)) : 0.0;
            $baseShare = $this->money($holderTotal - $holderVat);
            $withholdingRate = $fiscal?->withholding_enabled ? (float) $fiscal->withholding_rate : 0.0;
            $withholding = $this->money($baseShare * $withholdingRate / 100);
            $netPayable = $this->money($holderTotal - $withholding);
            $pending = $this->money($netPayable * $pendingRatio);

            $sale->allocations()->create([
                'content_creator_id' => $contract->content_creator_id,
                'contract_version_id' => $contract->id,
                'fiscal_profile_id' => $fiscal?->id,
                'holder_name' => $contract->creator?->name ?: 'Titular șters',
                'share_percent' => $contract->share_percent,
                'base_share_amount' => $baseShare,
                'vat_amount' => $holderVat,
                'gross_share_amount' => $holderTotal,
                'withholding_rate' => $withholdingRate,
                'withholding_amount' => $withholding,
                'net_payable_amount' => $netPayable,
                'available_amount' => $this->money($netPayable - $pending),
                'pending_settlement_amount' => $pending,
                'person_type' => $fiscal?->person_type,
                'is_vat_registered' => $vatHolder,
                'contract_snapshot' => $contract->only(['id', 'content_creator_id', 'content_id', 'share_percent', 'territories', 'effective_from', 'effective_until', 'contract_reference']),
                'fiscal_snapshot' => $fiscal?->only(['id', 'person_type', 'tax_residency', 'is_vat_registered', 'vat_rate', 'withholding_enabled', 'withholding_rate', 'tax_identifier', 'iban', 'payment_currency', 'effective_from', 'effective_until']),
            ]);

            $totals['base_share'] += $baseShare;
            $totals['holder_vat'] += $holderVat;
            $totals['gross'] += $holderTotal;
            $totals['withholding'] += $withholding;
            $totals['net'] += $netPayable;
        }

        $platformVat = $this->money(max(0, $vatOnPaid - $totals['holder_vat']));
        $platformShare = $this->money(max(0, $revenue - $deductions - $vatOnPaid - $totals['base_share']));

        $sale->forceFill([
            'calculation_status' => $calculationStatus,
            'calculation_version' => self::CALCULATION_VERSION,
            'distribution_base_amount' => $distributionBase,
            'holders_gross_amount' => $this->money($totals['gross']),
            'holders_vat_amount' => $this->money($totals['holder_vat']),
            'withholding_amount' => $this->money($totals['withholding']),
            'holders_net_amount' => $this->money($totals['net']),
            'platform_share_amount' => $platformShare,
            'platform_vat_amount' => $platformVat,
            'calculation_snapshot' => [
                ...(($sale->calculation_snapshot['version'] ?? null) !== null && ($sale->calculation_snapshot['version'] ?? null) !== self::CALCULATION_VERSION
                    ? ['previous' => $sale->calculation_snapshot] : []),
                'version' => self::CALCULATION_VERSION,
                'formula' => 'revenue = card + App Store money (promo excluded); deductions = store commission + conversion cost; '
                    .'base = revenue − deductions (VAT titular / export) or revenue − VAT on paid part − deductions (non-VAT titular); '
                    .'holder total = base × share (incl. holder invoice VAT for VAT titular); withholding = (total − holder VAT) × rate',
                'domestic_country_code' => $domesticCountry,
                'vat_rate' => $sale->vat_rate,
                'vat_on_paid_part' => $vatOnPaid,
                'promo_credits' => (float) $sale->promo_credits,
                'pending_settlement_ratio' => round($pendingRatio, 6),
                'rounding' => 'half-up, 2 decimals',
                'reconciled_amount' => $this->money($deductions + $totals['gross'] + $platformShare + $platformVat),
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

    /**
     * Everything the finance report needs in one pass over the sales: the
     * dashboard figures plus a per-holder breakdown and the full transaction
     * list. Buyer identity is deliberately left out so the report can be
     * forwarded to holders and management as-is.
     */
    public function financeReport(User $user, array $filters): array
    {
        $sales = $this->filteredSalesQuery($user, $filters)->with('allocations')->orderBy('purchased_at')->get();
        $visibleCreatorIds = $this->visibleCreatorIds($user);

        if ($visibleCreatorIds !== null) {
            $sales->each(fn (ReportingSale $sale) => $sale->setRelation(
                'allocations',
                $sale->allocations->whereIn('content_creator_id', $visibleCreatorIds)->values(),
            ));
        }

        $paid = $sales->where('gross_amount', '>', 0);
        $allocationSum = fn (Collection $rows, string $field): float => $this->money(
            (float) $rows->sum(fn (ReportingSale $s) => $s->allocations->sum($field)),
        );

        $byFilm = $sales->groupBy('content_id')->map(function (Collection $rows) use ($allocationSum): array {
            $first = $rows->first();

            return [
                'content_id' => $first?->content_id,
                'title' => $first?->content_title,
                'purchases' => $rows->where('gross_amount', '>', 0)->count(),
                'buyers' => $rows->pluck('buyer_user_id')->filter()->unique()->count(),
                'gross_amount' => $this->money($rows->sum('gross_amount')),
                'vat_amount' => $this->money($rows->sum('vat_amount')),
                'net_ex_vat_amount' => $this->money($rows->sum('net_ex_vat_amount')),
                'platform_share_amount' => $this->money($rows->sum('platform_share_amount')),
                'holder_gross_amount' => $allocationSum($rows, 'gross_share_amount'),
                'withholding_amount' => $allocationSum($rows, 'withholding_amount'),
                'net_payable_amount' => $allocationSum($rows, 'net_payable_amount'),
                'refund_amount' => $this->money($rows->sum('refund_amount')),
                'holders' => $rows->flatMap(fn (ReportingSale $s) => $s->allocations->pluck('holder_name'))->filter()->unique()->values(),
            ];
        })->sortByDesc('gross_amount')->values();

        $byHolder = $sales
            ->flatMap(fn (ReportingSale $sale) => $sale->allocations->map(fn ($allocation) => [$sale, $allocation]))
            ->groupBy(fn (array $pair) => $pair[1]->content_creator_id ?: $pair[1]->holder_name)
            ->map(function (Collection $pairs): array {
                $allocation = $pairs->first()[1];

                return [
                    'name' => $allocation->holder_name,
                    'person_type' => $allocation->person_type,
                    'is_vat_registered' => (bool) $allocation->is_vat_registered,
                    'share_percent' => $allocation->share_percent,
                    'films' => $pairs->map(fn (array $pair) => $pair[0]->content_title)->filter()->unique()->values(),
                    'purchases' => $pairs->filter(fn (array $pair) => $pair[0]->gross_amount > 0)->count(),
                    'gross_amount' => $this->money($pairs->sum(fn (array $pair) => $pair[0]->gross_amount)),
                    'gross_share_amount' => $this->money($pairs->sum(fn (array $pair) => $pair[1]->gross_share_amount)),
                    'withholding_amount' => $this->money($pairs->sum(fn (array $pair) => $pair[1]->withholding_amount)),
                    'net_payable_amount' => $this->money($pairs->sum(fn (array $pair) => $pair[1]->net_payable_amount)),
                ];
            })->sortByDesc('net_payable_amount')->values();

        return [
            'scope' => ['is_holder' => $visibleCreatorIds !== null],
            'summary' => [
                ...$this->summary($sales, $visibleCreatorIds !== null),
                'buyers' => $paid->pluck('buyer_user_id')->filter()->unique()->count(),
                'films_sold' => $paid->pluck('content_id')->unique()->count(),
                'refunds' => $sales->where('refund_amount', '>', 0)->count(),
                'without_holder' => $paid->filter(fn (ReportingSale $s) => $s->allocations->isEmpty())->count(),
            ],
            'by_film' => $byFilm,
            'by_holder' => $byHolder,
            'timeline' => $this->dimension($sales, fn (ReportingSale $s) => $s->purchased_at?->format('Y-m-d') ?? 'N/A'),
            'countries' => $this->dimension($sales, fn (ReportingSale $s) => $s->country_code ?: 'N/A'),
            'markets' => $this->dimension($sales, fn (ReportingSale $s) => $s->market ?: 'N/A'),
            'qualities' => $this->dimension($sales, fn (ReportingSale $s) => $s->quality ?: 'N/A'),
            'durations' => $this->dimension($sales, fn (ReportingSale $s) => $s->rental_days ? (string) $s->rental_days : 'permanent'),
            'payment_methods' => $this->dimension($sales, fn (ReportingSale $s) => $s->payment_method ?: 'N/A'),
            'transactions' => $sales->sortByDesc('purchased_at')->take(5000)->map(fn (ReportingSale $sale) => [
                'purchased_at' => $sale->purchased_at?->toIso8601String(),
                'film' => $sale->content_title,
                'offer' => $sale->offer_name,
                'quality' => $sale->quality,
                'rental_days' => $sale->rental_days,
                'country_code' => $sale->country_code,
                'market' => $sale->market,
                'payment_method' => $sale->payment_method,
                'gross_amount' => $sale->gross_amount,
                'vat_amount' => $sale->vat_amount,
                'platform_share_amount' => $sale->platform_share_amount,
                'holder_share_amount' => $this->money($sale->allocations->sum('gross_share_amount')),
                'holders' => $sale->allocations->pluck('holder_name')->filter()->implode(', '),
                'refund_amount' => $sale->refund_amount,
                'calculation_status' => $sale->calculation_status,
            ])->values(),
        ];
    }

    /** Payouts below this accumulate into the next months (contract 6.34). */
    public const MIN_PAYOUT = 1000.0;

    /**
     * The monthly commercial report per titular (contract 6.27–6.29), a)–j): the titular's works,
     * then one line per work × app × money source × fiscal regime with the transactions, revenue,
     * VAT, admitted deductions, distribution base, share, remuneration (its VAT apart), withholding
     * and net — the net split into "în curs de decontare" (App Store money not yet settled) and
     * "disponibil pentru plată". A sale paid from several sources is split pro rata to the money
     * each source actually brought in; promotional credit brings none (6.4).
     *
     * @param  array{month?: string, from?: string, to?: string, content_creator_id?: int}  $filters
     */
    public function commercialReport(User $user, array $filters): array
    {
        [$from, $to] = $this->reportPeriod($filters);
        $visibleCreatorIds = $this->visibleCreatorIds($user);
        $requested = isset($filters['content_creator_id']) ? [(int) $filters['content_creator_id']] : null;
        $holderIds = $visibleCreatorIds !== null
            ? ($requested !== null ? array_values(array_intersect($visibleCreatorIds, $requested)) : $visibleCreatorIds)
            : $requested;

        $sales = $this->filteredSalesQuery($user, ['from' => $from->toDateString(), 'to' => $to->toDateString()])
            ->with(['allocations' => fn ($query) => $query->when($holderIds !== null, fn ($q) => $q->whereIn('content_creator_id', $holderIds)), 'fundings'])
            ->orderBy('purchased_at')
            ->get();

        $pairs = $sales->flatMap(fn (ReportingSale $sale) => $sale->allocations->map(fn ($allocation) => [$sale, $allocation]));
        $ids = $holderIds ?? $pairs->pluck('1.content_creator_id')->filter()->unique()->values()->all();
        $holders = ContentCreator::query()->with('contents:id,original_title')->whereIn('id', $ids)->orderBy('name')->get();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'month' => $from->format('Y-m')],
            'generated_at' => now()->toIso8601String(),
            'currency' => 'MDL',
            'holders' => $holders->map(fn (ContentCreator $holder) => $this->holderStatement(
                $holder,
                $pairs->filter(fn (array $pair) => (int) $pair[1]->content_creator_id === $holder->id)->values(),
            ))->values(),
            'notes' => [
                'App Store: valori estimate (preț × curs − comision Apple − cost conversie) până la reconcilierea decontării Apple; devin disponibile pentru plată după încasare (art. 6.29).',
                'Credit promoțional (bonus): nu constituie venit încasat și nu intră în Baza de Repartizare (art. 6.4).',
                'Comisioanele bancare și de procesare (MAIB, Visa, Mastercard) nu diminuează Baza de Repartizare (art. 6.7).',
            ],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function reportPeriod(array $filters): array
    {
        if (! empty($filters['month'])) {
            $start = Carbon::createFromFormat('Y-m', (string) $filters['month'])->startOfMonth();

            return [$start, $start->copy()->endOfMonth()];
        }
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            return [
                Carbon::parse($filters['from'] ?? '2000-01-01')->startOfDay(),
                Carbon::parse($filters['to'] ?? now()->toDateString())->endOfDay(),
            ];
        }
        $previous = now()->subMonthNoOverflow()->startOfMonth(); // 6.27: the previous calendar month

        return [$previous, $previous->copy()->endOfMonth()];
    }

    /** @param  Collection<int, array{0: ReportingSale, 1: \App\Models\ReportingSaleAllocation}>  $pairs */
    protected function holderStatement(ContentCreator $holder, Collection $pairs): array
    {
        $lines = [];
        foreach ($pairs as [$sale, $allocation]) {
            $price = (float) $sale->gross_amount;
            $paidNet = (float) $sale->fundings->where('source', '!=', WalletFundingLot::SOURCE_PLATFORM)->sum('net_value');
            $fundings = $sale->fundings->isNotEmpty() ? $sale->fundings : collect([null]);

            foreach ($fundings as $funding) {
                $source = $funding?->source ?? WalletFundingLot::SOURCE_OWN;
                $isPromo = $source === WalletFundingLot::SOURCE_PLATFORM;
                $weight = $isPromo || $funding === null ? ($funding === null ? 1.0 : 0.0) : ($paidNet > 0 ? (float) $funding->net_value / $paidNet : 0.0);
                $credits = $funding !== null ? (float) $funding->credits : $price;
                $pending = $source === WalletFundingLot::SOURCE_APPLE && $funding?->valuation_status === WalletFundingLot::VALUATION_ESTIMATED;
                $key = implode('|', [$sale->content_id, $sale->sales_channel ?: 'web', $source, $sale->market]);

                $line = $lines[$key] ?? [
                    'content_id' => $sale->content_id, 'film' => $sale->content_title, 'channel' => $sale->sales_channel ?: 'web',
                    'money_source' => $source, 'market' => $sale->market, 'sale_ids' => [],
                    'credits' => 0.0, 'promo_credits' => 0.0, 'revenue_gross' => 0.0, 'vat_collected' => 0.0, 'commission' => 0.0,
                    'fx_cost' => 0.0, 'refunds' => 0.0, 'deductions' => 0.0, 'base' => 0.0, 'share_percent' => (float) $allocation->share_percent,
                    'remuneration_total' => 0.0, 'remuneration_vat' => 0.0, 'withholding' => 0.0, 'net' => 0.0,
                    'pending_settlement' => 0.0, 'available' => 0.0, 'estimated' => false,
                ];
                $line['sale_ids'][$sale->id] = true;
                $line['credits'] += $credits;
                $line['estimated'] = $line['estimated'] || $pending;
                if ($isPromo) {
                    $line['promo_credits'] += $credits;
                } else {
                    $line['revenue_gross'] += (float) ($funding?->gross_value ?? $sale->revenue_gross_amount);
                    $line['vat_collected'] += $price > 0 ? (float) $sale->vat_amount * $credits / $price : 0.0;
                    $line['commission'] += (float) ($funding?->commission_value ?? $sale->channel_commission_amount);
                    $line['fx_cost'] += (float) ($funding?->fx_cost_value ?? $sale->fx_cost_amount);
                    $line['refunds'] += (float) $sale->refund_amount * $weight;
                    $line['base'] += (float) $sale->distribution_base_amount * $weight;
                    $line['remuneration_total'] += (float) $allocation->gross_share_amount * $weight;
                    $line['remuneration_vat'] += (float) $allocation->vat_amount * $weight;
                    $line['withholding'] += (float) $allocation->withholding_amount * $weight;
                    $net = (float) $allocation->net_payable_amount * $weight;
                    $line['net'] += $net;
                    $line[$pending ? 'pending_settlement' : 'available'] += $net;
                }
                $lines[$key] = $line;
            }
        }

        $lines = collect($lines)->map(function (array $line): array {
            $line['transactions'] = count($line['sale_ids']);
            unset($line['sale_ids']);
            $line['deductions'] = $line['commission'] + $line['fx_cost'] + $line['refunds'];
            foreach (['credits', 'promo_credits', 'revenue_gross', 'vat_collected', 'commission', 'fx_cost', 'refunds', 'deductions', 'base',
                'remuneration_total', 'remuneration_vat', 'withholding', 'net', 'pending_settlement', 'available'] as $field) {
                $line[$field] = $this->money($line[$field]);
            }

            return $line;
        })->sortBy([['film', 'asc'], ['channel', 'asc'], ['money_source', 'asc']])->values();

        $sum = fn (string $field): float => $this->money((float) $lines->sum($field));
        $available = $sum('available');
        $soldIds = $lines->pluck('content_id')->unique();

        return [
            'holder' => [
                'id' => $holder->id, 'name' => $holder->name, 'company_name' => $holder->company_name,
                'person_type' => $holder->person_type, 'idno' => $holder->idno, 'is_vat_payer' => (bool) $holder->is_vat_payer,
                'vat_code' => $holder->vat_code, 'legal_address' => $holder->legal_address,
            ],
            // a) the works: everything the titular holds, sold this month or not
            'works' => $holder->contents->map(fn ($content) => [
                'content_id' => $content->id, 'title' => $content->original_title, 'had_sales' => $soldIds->contains($content->id),
            ])->sortBy('title')->values(),
            'lines' => $lines,
            'totals' => [
                'transactions' => $pairs->count(),                         // b)
                'credits' => $sum('credits'),
                'promo_credits' => $sum('promo_credits'),
                'revenue_gross' => $sum('revenue_gross'),                  // c)
                'vat_collected' => $sum('vat_collected'),                  // d)
                'commission' => $sum('commission'),                        // e)
                'fx_cost' => $sum('fx_cost'),
                'refunds' => $sum('refunds'),
                'deductions' => $sum('deductions'),
                'base' => $sum('base'),                                    // f)
                'share_percent' => $lines->pluck('share_percent')->unique()->count() === 1 ? $lines->first()['share_percent'] : null, // g)
                'remuneration_total' => $sum('remuneration_total'),        // h)
                'remuneration_vat' => $sum('remuneration_vat'),
                'withholding' => $sum('withholding'),                      // i)
                'net' => $sum('net'),
                'pending_settlement' => $sum('pending_settlement'),        // 6.29
                'available' => $available,
                'carried_over' => null,                                    // j) needs the payout register (stage 2)
                'below_minimum' => $available > 0 && $available < self::MIN_PAYOUT, // 6.34
                'min_payout' => self::MIN_PAYOUT,
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

            $profile = CreatorFiscalProfile::query()->create([
                ...$payload, 'tax_residency' => strtoupper($payload['tax_residency']), 'payment_currency' => strtoupper($payload['payment_currency']),
                'vat_rate' => $payload['is_vat_registered'] ? $payload['vat_rate'] : 0,
                'withholding_rate' => $payload['withholding_enabled'] ? $payload['withholding_rate'] : 0,
                'status' => 'active', 'created_by' => $createdBy,
            ]);

            // The titular is the single source of these fields: a profile in force from today
            // (entered here in reporting) is written back to it.
            if ($this->isCurrentlyActive($profile)) {
                ContentCreator::query()->whereKey($profile->content_creator_id)->update([
                    'person_type' => $profile->person_type,
                    'is_vat_payer' => (bool) $profile->is_vat_registered,
                    'idno' => $profile->tax_identifier,
                    'iban' => $profile->iban,
                ]);
            }

            return $profile;
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

            // One titular per title: these titles move to this holder.
            $creator->assignTitles($payload['content_ids']);

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
