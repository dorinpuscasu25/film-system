<?php

namespace App\Services\Payments;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\User;

/**
 * One way money reaches a wallet top-up (pay.filmoteca.md, Victoriabank MIA, CyberSource).
 * Settling the wallet itself is TopUpSettlementService's job, shared by every provider.
 */
interface TopUpPaymentProvider
{
    /** Asks the provider for the current state and settles the top-up if it changed. */
    public function refreshStatus(PaymentTopUp $topUp, bool $force = false): PaymentTopUp;

    public function refundTopUp(PaymentTopUp $topUp, float $amount, string $reason, ?User $admin = null): PaymentRefund;

    /**
     * Background safety net for top-ups whose notification never arrived.
     *
     * @return array<string, int>
     */
    public function pollPendingTopUps(int $limit = 50): array;
}
