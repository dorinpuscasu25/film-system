<?php

namespace App\Services;

use App\Models\ReportingSale;
use App\Models\Wallet;
use App\Models\WalletFundingLot;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off rebuild for data from before the funding ledger: replays every wallet's history into
 * lots (each credit) and allocations (each debit, oldest credit first, in the wallet's spend
 * order), then recalculates reporting sales with the contract formula (v2). Safe to re-run:
 * wallets that already have lots are left alone, and only sales not yet on v2 are recalculated
 * unless asked otherwise.
 */
class FundingLedgerBackfill
{
    public function __construct(
        protected WalletFundingLedger $ledger,
        protected RightsReportingService $reporting,
    ) {}

    /** @return array{wallets: int, lots: int, allocations: int} */
    public function rebuildLots(): array
    {
        $stats = ['wallets' => 0, 'lots' => 0, 'allocations' => 0];

        Wallet::query()
            ->whereDoesntHave('fundingLots')
            ->whereHas('transactions')
            ->orderBy('id')
            ->chunkById(100, function (Collection $wallets) use (&$stats): void {
                foreach ($wallets as $wallet) {
                    DB::transaction(function () use ($wallet, &$stats): void {
                        $transactions = $wallet->transactions()->orderBy('processed_at')->orderBy('id')->get();
                        foreach ($transactions as $transaction) {
                            $amount = round((float) $transaction->amount, 2);
                            if ($amount > 0) {
                                $this->ledger->openLot($transaction, $this->sourceOf($transaction), $this->referenceOf($transaction));
                                $stats['lots']++;
                            } elseif ($amount < 0) {
                                $stats['allocations'] += $this->ledger->consume(
                                    $transaction,
                                    $this->debitSplit($transaction, abs($amount)),
                                    $this->referenceOf($transaction),
                                )->count();
                            }
                        }
                        $stats['wallets']++;
                    });
                }
            });

        return $stats;
    }

    /** @return array{recalculated: int} */
    public function recalculateSales(bool $all = false): array
    {
        $count = 0;

        ReportingSale::query()
            ->when(! $all, fn ($query) => $query->where('calculation_version', '!=', RightsReportingService::CALCULATION_VERSION))
            ->orderBy('id')
            ->chunkById(200, function (Collection $sales) use (&$count): void {
                foreach ($sales as $sale) {
                    DB::transaction(function () use ($sale, &$count): void {
                        $at = Carbon::parse($sale->purchased_at);
                        $transaction = $sale->wallet_transaction_id !== null
                            ? WalletTransaction::query()->find($sale->wallet_transaction_id)
                            : $this->reporting->purchaseTransactionForSale($sale);
                        $this->reporting->recordFundings($sale, $transaction, $at);
                        $this->reporting->recalculate($sale);
                        $count++;
                    });
                }
            });

        return ['recalculated' => $count];
    }

    protected function sourceOf(WalletTransaction $transaction): string
    {
        if ($transaction->type === WalletTransaction::TYPE_WELCOME_BONUS) {
            return WalletFundingLot::SOURCE_PLATFORM;
        }
        $source = $transaction->meta['funding_source'] ?? null;

        return in_array($source, [WalletFundingLot::SOURCE_APPLE, WalletFundingLot::SOURCE_PLATFORM], true) ? $source : WalletFundingLot::SOURCE_OWN;
    }

    /** @return array<string, float> */
    protected function debitSplit(WalletTransaction $transaction, float $amount): array
    {
        $meta = $transaction->meta ?? [];
        if (array_key_exists('own_amount', $meta) || array_key_exists('platform_amount', $meta) || array_key_exists('apple_amount', $meta)) {
            return [
                WalletFundingLot::SOURCE_PLATFORM => (float) ($meta['platform_amount'] ?? 0),
                WalletFundingLot::SOURCE_APPLE => (float) ($meta['apple_amount'] ?? 0),
                WalletFundingLot::SOURCE_OWN => (float) ($meta['own_amount'] ?? 0),
            ];
        }

        // Debits older than the per-source split: spend in the wallet's order from what is left.
        $split = [];
        foreach (WalletFundingLot::SPEND_ORDER as $source) {
            $available = (float) WalletFundingLot::query()->where('wallet_id', $transaction->wallet_id)->where('source', $source)->sum('remaining_amount');
            $split[$source] = round(min($amount, $available), 2);
            $amount = round($amount - $split[$source], 2);
        }

        return $split;
    }

    protected function referenceOf(WalletTransaction $transaction): ?Model
    {
        if ($transaction->reference_type === null || $transaction->reference_id === null) {
            return null;
        }
        $class = Relation::getMorphedModel($transaction->reference_type) ?? $transaction->reference_type;

        return class_exists($class) ? $class::query()->find($transaction->reference_id) : null;
    }
}
