<?php

namespace App\Services\Payments;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\User;
use App\Models\Wallet;
use App\Services\PayFilmotecaPaymentService;
use App\Services\Payments\CyberSource\CyberSourcePaymentService;
use App\Services\Payments\Mia\MiaPaymentService;

/**
 * Routes each top-up to the provider that created it, so controllers and the poller do
 * not need to know which bank channel a payment went through.
 */
class TopUpPaymentGateway
{
    public function __construct(
        protected PayFilmotecaPaymentService $payFilmoteca,
        protected MiaPaymentService $mia,
        protected TopUpSettlementService $settlement,
        protected CyberSourcePaymentService $cyberSource,
    ) {}

    /** Which provider takes a new card top-up: Victoriabank CyberSource or the legacy pay.filmoteca.md. */
    public function cardProvider(): string
    {
        return config('services.payments.card_provider') === PaymentTopUp::PROVIDER_CYBERSOURCE
            ? PaymentTopUp::PROVIDER_CYBERSOURCE
            : PaymentTopUp::PROVIDER_PAY_FILMOTECA;
    }

    /** pay.filmoteca.md needs a phone number for its checkout; the bank's own channels do not. */
    public function cardRequiresPhone(): bool
    {
        return $this->cardProvider() === PaymentTopUp::PROVIDER_PAY_FILMOTECA;
    }

    public function provider(PaymentTopUp $topUp): TopUpPaymentProvider
    {
        return match ($topUp->provider) {
            PaymentTopUp::PROVIDER_MIA => $this->mia,
            PaymentTopUp::PROVIDER_CYBERSOURCE => $this->cyberSource,
            default => $this->payFilmoteca,
        };
    }

    /**
     * @return array<string, bool>
     */
    public function availableMethods(): array
    {
        return [
            PaymentTopUp::METHOD_CARD => (bool) config('services.payments.card_enabled', true)
                && ($this->cardProvider() !== PaymentTopUp::PROVIDER_CYBERSOURCE || $this->cyberSource->isAvailable()),
            PaymentTopUp::METHOD_MIA => $this->mia->isAvailable(),
        ];
    }

    public function refreshStatus(PaymentTopUp $topUp, bool $force = false): PaymentTopUp
    {
        return $this->provider($topUp)->refreshStatus($topUp, $force);
    }

    public function refundTopUp(PaymentTopUp $topUp, float $amount, string $reason, ?User $admin = null): PaymentRefund
    {
        return $this->provider($topUp)->refundTopUp($topUp, $amount, $reason, $admin);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function pollPendingTopUps(int $limit = 50): array
    {
        return [
            PaymentTopUp::PROVIDER_PAY_FILMOTECA => $this->payFilmoteca->pollPendingTopUps($limit),
            PaymentTopUp::PROVIDER_MIA => $this->mia->pollPendingTopUps($limit),
            PaymentTopUp::PROVIDER_CYBERSOURCE => $this->cyberSource->pollPendingTopUps($limit),
        ];
    }

    public function topUpData(PaymentTopUp $topUp): array
    {
        return [
            ...$this->payFilmoteca->topUpData($topUp),
            'provider' => $topUp->provider,
            'payment_method' => $topUp->payment_method,
            'provider_reference' => $topUp->provider_reference,
            'qr' => $this->mia->qrData($topUp),
            'card_session' => $this->cyberSource->sessionData($topUp),
        ];
    }

    /** MIA allows a single refund per payment; the admin UI must say so before confirming. */
    public function refundPolicy(PaymentTopUp $topUp): array
    {
        $singleRefund = $topUp->provider === PaymentTopUp::PROVIDER_MIA;

        return [
            'single_refund_only' => $singleRefund,
            'locked' => $singleRefund && $topUp->refunds()
                ->whereIn('status', [PaymentRefund::STATUS_SUCCEEDED, PaymentRefund::STATUS_REQUESTED])
                ->exists(),
            'minimum_amount' => $topUp->provider === PaymentTopUp::PROVIDER_PAY_FILMOTECA ? 20.0 : 0.01,
        ];
    }

    public function refundedAmount(PaymentTopUp $topUp): float
    {
        return $this->settlement->refundedAmount($topUp);
    }

    public function refundableAmount(PaymentTopUp $topUp): float
    {
        return $this->settlement->refundableAmount($topUp);
    }

    public function ownCreditBalance(?Wallet $wallet): float
    {
        return $this->settlement->ownCreditBalance($wallet);
    }
}
