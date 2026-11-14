<?php

namespace App\Services;

use App\Models\AppleIapTransaction;
use App\Models\CostSettingsVersion;
use App\Models\WalletFundingAllocation;
use App\Models\WalletFundingLot;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Lot-level record of where wallet credit came from and which credit each debit used.
 *
 * WalletService keeps the per-source balances (platform → apple → own) that decide how much of a
 * debit comes from each source; this ledger adds *which* top-up or pack that was, oldest first,
 * and what it was worth in real money (contract 6.5):
 *  - own (card, MAIB): 1 credit = 1 MDL; bank and processing fees are not deducted (6.7).
 *  - apple: the pack's App Store price × the USD→MDL rate in force, minus Apple's commission and
 *    the conversion cost. An estimate until Apple's payout is reconciled (stage 2).
 *  - platform (bonus, admin credit): promotional, no revenue (6.4).
 */
class WalletFundingLedger
{
    public function __construct(protected IapCreditPackService $creditPacks) {}

    public function openLot(WalletTransaction $credit, string $source, ?Model $reference = null): WalletFundingLot
    {
        $amount = round(abs((float) $credit->amount), 2);
        $valuation = $this->valuation($source, $amount, $reference, $credit->processed_at ?? now());

        return WalletFundingLot::query()->create([
            'wallet_id' => $credit->wallet_id,
            'user_id' => $credit->user_id,
            'source' => $source,
            'credit_transaction_id' => $credit->id,
            'source_type' => $reference?->getMorphClass() ?? $credit->reference_type,
            'source_id' => $reference?->getKey() ?? $credit->reference_id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'currency' => $credit->currency ?: 'MDL',
            'funded_at' => $credit->processed_at ?? now(),
            ...$valuation,
        ]);
    }

    /**
     * Records which lots a debit used. Each source's amount is taken from that source's lots,
     * oldest first — or from the lot of $preferred first (an Apple refund claws back that pack).
     * Credit with no lot (wallets from before the ledger existed) is simply left unallocated.
     *
     * @param  array<string, float>  $amountsBySource  source => credits taken from it
     * @return Collection<int, WalletFundingAllocation>
     */
    public function consume(WalletTransaction $debit, array $amountsBySource, ?Model $preferred = null): Collection
    {
        $allocations = collect();

        foreach (WalletFundingLot::SPEND_ORDER as $source) {
            $needed = round((float) ($amountsBySource[$source] ?? 0), 2);
            if ($needed <= 0) {
                continue;
            }

            $lots = WalletFundingLot::query()
                ->where('wallet_id', $debit->wallet_id)
                ->where('source', $source)
                ->where('remaining_amount', '>', 0)
                ->when($preferred !== null, fn ($query) => $query->orderByRaw(
                    'CASE WHEN source_type = ? AND source_id = ? THEN 0 ELSE 1 END',
                    [$preferred->getMorphClass(), $preferred->getKey()],
                ))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                if ($needed <= 0) {
                    break;
                }
                $take = round(min($needed, (float) $lot->remaining_amount), 2);
                $lot->forceFill(['remaining_amount' => round((float) $lot->remaining_amount - $take, 2)])->save();
                $allocations->push(WalletFundingAllocation::query()->create([
                    'debit_transaction_id' => $debit->id,
                    'lot_id' => $lot->id,
                    'amount' => $take,
                ]));
                $needed = round($needed - $take, 2);
            }
        }

        return $allocations;
    }

    /** @return array<string, mixed> valuation columns for a new lot */
    public function valuation(string $source, float $amount, ?Model $reference, CarbonInterface $at): array
    {
        if ($source === WalletFundingLot::SOURCE_PLATFORM) {
            return [
                'paid_amount' => 0, 'paid_currency' => 'MDL', 'gross_value' => 0, 'commission_value' => 0,
                'fx_cost_value' => 0, 'net_value' => 0, 'valuation_status' => WalletFundingLot::VALUATION_FINAL,
                'valuation_meta' => ['rule' => 'promotional credit: no revenue (contract 6.4)'],
            ];
        }

        if ($source === WalletFundingLot::SOURCE_APPLE) {
            return $this->appleValuation($amount, $reference instanceof AppleIapTransaction ? $reference : null, $at);
        }

        return [
            'paid_amount' => $amount, 'paid_currency' => 'MDL', 'gross_value' => $amount, 'commission_value' => 0,
            'fx_cost_value' => 0, 'net_value' => $amount, 'valuation_status' => WalletFundingLot::VALUATION_FINAL,
            'valuation_meta' => ['rule' => 'card payment: 1 credit = 1 MDL; bank fees not deducted (contract 6.7)'],
        ];
    }

    /**
     * Estimated value of an App Store pack: what the customer paid × the rate in force, minus
     * the configured commission and conversion cost. Falls back to 1 credit = 1 MDL gross when
     * the price or the rate is unknown, and says so in valuation_meta.
     */
    protected function appleValuation(float $credits, ?AppleIapTransaction $transaction, CarbonInterface $at): array
    {
        $settings = $this->creditPacks->settings();
        $commissionRate = (float) ($settings['commission_rate'] ?? 0.30);
        $fxCostRate = (float) ($settings['fx_margin_rate'] ?? 0);

        $paid = $transaction?->apple_price !== null ? round($transaction->apple_price / 1000, 4) : null; // Apple sends milli-units
        $currency = strtoupper((string) ($transaction?->apple_currency ?? ''));
        $rate = $currency === 'MDL' ? 1.0 : ($currency === 'USD' ? $this->usdToMdl($at) : null);

        $meta = ['commission_rate' => $commissionRate, 'fx_cost_rate' => $fxCostRate, 'rate' => $rate];
        if ($paid !== null && $rate !== null) {
            $gross = round($paid * $rate, 4);
            $meta['rule'] = 'estimated: App Store price × rate − commission − conversion cost (contract 6.5 a, c)';
        } else {
            $gross = $credits;
            $meta['rule'] = 'estimated: App Store price or rate unknown, valued at 1 credit = 1 MDL gross';
            $meta['fallback'] = $paid === null ? 'price_missing' : "no_rate_for_{$currency}";
        }

        $commission = round($gross * $commissionRate, 4);
        $fxCost = round(($gross - $commission) * $fxCostRate, 4);

        return [
            'paid_amount' => $paid,
            'paid_currency' => $currency !== '' ? $currency : null,
            'gross_value' => $gross,
            'commission_value' => $commission,
            'fx_cost_value' => $fxCost,
            'net_value' => round($gross - $commission - $fxCost, 4),
            'valuation_status' => WalletFundingLot::VALUATION_ESTIMATED,
            'valuation_meta' => $meta,
        ];
    }

    protected function usdToMdl(CarbonInterface $at): ?float
    {
        $rate = CostSettingsVersion::query()
            ->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->latest('effective_from')
            ->value('usd_to_mdl_rate')
            ?? CostSettingsVersion::query()->where('is_active', true)->latest('effective_from')->value('usd_to_mdl_rate');

        return $rate !== null && (float) $rate > 0 ? (float) $rate : null;
    }
}
