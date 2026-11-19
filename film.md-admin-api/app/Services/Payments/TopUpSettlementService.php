<?php

namespace App\Services\Payments;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The provider-agnostic half of a wallet top-up: crediting the wallet exactly once when a
 * provider confirms the money arrived, and debiting it when a refund is confirmed.
 * Providers (pay.filmoteca.md, Victoriabank MIA, CyberSource) only decide *whether* a
 * payment or refund succeeded; this class owns what that means for the wallet.
 */
class TopUpSettlementService
{
    public function __construct(
        protected WalletService $wallets,
    ) {}

    public static function providerLabel(?string $provider): string
    {
        return match ($provider) {
            PaymentTopUp::PROVIDER_MIA => 'victoriabank.mia',
            PaymentTopUp::PROVIDER_CYBERSOURCE => 'victoriabank.cybersource',
            default => 'pay.filmoteca.md',
        };
    }

    /**
     * Credits the wallet for a confirmed top-up. Safe to call repeatedly: the row is
     * locked and `credited_at` makes every call after the first a no-op.
     */
    public function creditWallet(PaymentTopUp $topUp, array $details, ?string $providerStatus): void
    {
        DB::transaction(function () use ($topUp, $details, $providerStatus): void {
            $lockedTopUp = PaymentTopUp::query()
                ->whereKey($topUp->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTopUp->credited_at !== null) {
                Log::channel('payments')->info('Top-up wallet credit skipped because top-up was already credited', [
                    'top_up_uuid' => $lockedTopUp->uuid,
                    'provider' => $lockedTopUp->provider,
                    'wallet_id' => $lockedTopUp->wallet_id,
                    'credited_at' => $lockedTopUp->credited_at?->toIso8601String(),
                    'status' => $lockedTopUp->status,
                ]);

                return;
            }

            $wallet = Wallet::query()
                ->whereKey($lockedTopUp->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $providerLabel = self::providerLabel($lockedTopUp->provider);

            $credit = $this->wallets->credit(
                $wallet,
                (float) $lockedTopUp->amount,
                WalletTransaction::TYPE_TOP_UP,
                'Wallet top-up via '.$providerLabel,
                [
                    'payment_top_up_id' => $lockedTopUp->uuid,
                    'provider' => $providerLabel,
                    'payment_method' => $lockedTopUp->payment_method,
                    'provider_reference' => $lockedTopUp->provider_reference,
                    'provider_order_id' => $lockedTopUp->provider_order_id,
                    'provider_checkout_id' => $lockedTopUp->provider_checkout_id,
                    'provider_rrn' => $lockedTopUp->provider_rrn,
                    'provider_status' => $providerStatus,
                    'details' => $details,
                ],
                $lockedTopUp,
            );

            if ($lockedTopUp->access_location !== null) {
                $credit->forceFill(['access_location' => $lockedTopUp->access_location])->save();
            }

            $lockedTopUp->forceFill([
                'status' => PaymentTopUp::STATUS_PAID,
                'provider_status' => $providerStatus,
                'raw_details' => $details,
                'credited_at' => now(),
            ])->save();

            Log::channel('payments')->info('Top-up wallet credit completed', [
                'top_up_uuid' => $lockedTopUp->uuid,
                'provider' => $lockedTopUp->provider,
                'wallet_id' => $wallet->id,
                'user_id' => $lockedTopUp->user_id,
                'amount' => $lockedTopUp->amount,
                'currency' => $lockedTopUp->currency,
                'provider_reference' => $lockedTopUp->provider_reference,
                'provider_order_id' => $lockedTopUp->provider_order_id,
                'provider_rrn' => $lockedTopUp->provider_rrn,
                'provider_status' => $providerStatus,
                'credited_at' => $lockedTopUp->credited_at?->toIso8601String(),
            ]);
        });
    }

    /**
     * Applies a refund the provider has confirmed: debits the customer-paid part of the
     * wallet, marks the refund succeeded and the top-up refunded once fully returned.
     */
    public function settleRefund(PaymentRefund $refund, mixed $rawResponse, ?string $providerStatus, string $description): PaymentRefund
    {
        return DB::transaction(function () use ($refund, $rawResponse, $providerStatus, $description): PaymentRefund {
            $lockedRefund = PaymentRefund::query()
                ->whereKey($refund->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRefund->status === PaymentRefund::STATUS_SUCCEEDED) {
                return $lockedRefund;
            }

            $lockedTopUp = PaymentTopUp::query()
                ->whereKey($lockedRefund->payment_top_up_id)
                ->lockForUpdate()
                ->firstOrFail();

            $wallet = Wallet::query()
                ->whereKey($lockedRefund->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $maxRefundable = $this->refundableAmount($lockedTopUp);
            if ((float) $lockedRefund->amount > $maxRefundable) {
                $lockedRefund->forceFill([
                    'status' => PaymentRefund::STATUS_FAILED,
                    'provider_status' => 'local_refund_limit_exceeded',
                    'raw_response' => $rawResponse,
                    'processed_at' => now(),
                ])->save();

                throw ValidationException::withMessages([
                    'amount' => [sprintf('Suma maximă disponibilă pentru refund este %.2f %s.', $maxRefundable, $lockedRefund->currency)],
                ]);
            }

            $transaction = $this->wallets->debitOwnCredit(
                $wallet,
                (float) $lockedRefund->amount,
                WalletTransaction::TYPE_REFUND,
                $description,
                [
                    'payment_refund_id' => $lockedRefund->uuid,
                    'payment_top_up_id' => $lockedTopUp->uuid,
                    'provider' => self::providerLabel($lockedTopUp->provider),
                    'provider_order_id' => $lockedRefund->provider_order_id,
                    'provider_checkout_id' => $lockedRefund->provider_checkout_id,
                    'provider_rrn' => $lockedRefund->provider_rrn,
                    'refund_reason' => $lockedRefund->reason,
                ],
                $lockedRefund,
            );

            $lockedRefund->forceFill([
                'wallet_transaction_id' => $transaction->id,
                'status' => PaymentRefund::STATUS_SUCCEEDED,
                'provider_status' => $providerStatus,
                'raw_response' => $rawResponse,
                'processed_at' => now(),
            ])->save();

            if ($this->refundedAmount($lockedTopUp->fresh()) >= round((float) $lockedTopUp->amount, 2)) {
                $lockedTopUp->forceFill([
                    'status' => PaymentTopUp::STATUS_REFUNDED,
                ])->save();
            }

            Log::channel('payments')->info('Top-up refund settled', [
                'payment_refund_uuid' => $lockedRefund->uuid,
                'top_up_uuid' => $lockedTopUp->uuid,
                'provider' => $lockedTopUp->provider,
                'wallet_transaction_id' => $transaction->id,
                'amount' => $lockedRefund->amount,
                'currency' => $lockedRefund->currency,
            ]);

            return $lockedRefund->fresh();
        });
    }

    public function refundedAmount(PaymentTopUp $topUp): float
    {
        return round((float) $topUp->refunds()
            ->where('status', PaymentRefund::STATUS_SUCCEEDED)
            ->sum('amount'), 2);
    }

    public function refundableAmount(PaymentTopUp $topUp): float
    {
        $topUp->loadMissing('wallet');
        $alreadyRefunded = $this->refundedAmount($topUp);
        $remainingTopUpAmount = max(0, round((float) $topUp->amount - $alreadyRefunded, 2));
        $ownBalance = $this->ownCreditBalance($topUp->wallet);

        return round(min($remainingTopUpAmount, $ownBalance), 2);
    }

    public function ownCreditBalance(?Wallet $wallet): float
    {
        if ($wallet === null) {
            return 0.0;
        }

        $meta = $wallet->meta ?? [];

        return round((float) ($meta['own_credit_balance'] ?? $wallet->balance_amount ?? 0), 2);
    }
}
