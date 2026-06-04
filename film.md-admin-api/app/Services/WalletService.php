<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function __construct(
        protected RegistrationCreditService $registrationCredit,
    ) {}

    public function ensureWallet(User $user): Wallet
    {
        $existingWallet = $user->wallet()->first();
        if ($existingWallet !== null) {
            return $existingWallet;
        }

        try {
            return DB::transaction(function () use ($user): Wallet {
                $wallet = $user->wallet()->first();
                if ($wallet !== null) {
                    return $wallet;
                }

                $registrationCredit = $this->registrationCredit->resolveForRegistration($user->created_at);
                $welcomeCredit = $registrationCredit['enabled'] ? $registrationCredit['amount'] : 0.0;

                $wallet = $user->wallet()->create([
                    'currency' => Wallet::DEFAULT_CURRENCY,
                    'balance_amount' => $welcomeCredit,
                    'meta' => [
                        'source' => 'system',
                        'initial_credit' => $welcomeCredit,
                        'platform_credit_balance' => $welcomeCredit,
                        'own_credit_balance' => 0,
                        'registration_credit' => [
                            'enabled' => $registrationCredit['enabled'],
                            'amount' => $welcomeCredit,
                            'campaign' => $registrationCredit['campaign'],
                        ],
                    ],
                ]);

                if ($welcomeCredit > 0) {
                    $this->recordTransaction(
                        $wallet,
                        WalletTransaction::TYPE_WELCOME_BONUS,
                        $welcomeCredit,
                        'Welcome credit',
                        [
                            'reason' => 'automatic_welcome_bonus',
                            'funding_source' => 'platform',
                            'platform_amount' => $welcomeCredit,
                            'own_amount' => 0,
                            'campaign' => $registrationCredit['campaign'],
                        ],
                    );
                }

                return $wallet;
            });
        } catch (QueryException) {
            return $user->wallet()->firstOrFail();
        }
    }

    public function lockWallet(User $user): Wallet
    {
        return Wallet::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function debit(
        Wallet $wallet,
        float $amount,
        string $type,
        ?string $description = null,
        array $meta = [],
        ?Model $reference = null,
    ): WalletTransaction {
        $normalizedAmount = round(abs($amount), 2);
        $currentBalance = round((float) $wallet->balance_amount, 2);

        if ($normalizedAmount > $currentBalance) {
            throw ValidationException::withMessages([
                'wallet' => ['Insufficient wallet balance for this purchase.'],
            ]);
        }

        $funding = $this->allocateDebitFunding($wallet, $normalizedAmount);
        $newBalance = round($currentBalance - $normalizedAmount, 2);
        $wallet->forceFill([
            'balance_amount' => $newBalance,
            'meta' => [
                ...($wallet->meta ?? []),
                'platform_credit_balance' => $funding['platform_credit_balance_after'],
                'own_credit_balance' => $funding['own_credit_balance_after'],
            ],
        ])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            -$normalizedAmount,
            $description,
            [
                ...$meta,
                'platform_amount' => $funding['platform_amount'],
                'own_amount' => $funding['own_amount'],
                'platform_percent' => $normalizedAmount > 0 ? round($funding['platform_amount'] / $normalizedAmount * 100, 2) : 0,
                'own_percent' => $normalizedAmount > 0 ? round($funding['own_amount'] / $normalizedAmount * 100, 2) : 0,
                'funding_source' => $this->fundingSourceLabel($funding['platform_amount'], $funding['own_amount']),
            ],
            $reference,
            $newBalance,
        );
    }

    public function credit(
        Wallet $wallet,
        float $amount,
        string $type,
        ?string $description = null,
        array $meta = [],
        ?Model $reference = null,
    ): WalletTransaction {
        $normalizedAmount = round(abs($amount), 2);
        $currentBalance = round((float) $wallet->balance_amount, 2);
        $newBalance = round($currentBalance + $normalizedAmount, 2);
        $balances = $this->fundingBalances($wallet);
        $isPlatformCredit = $type === WalletTransaction::TYPE_WELCOME_BONUS
            || ($meta['funding_source'] ?? null) === 'platform';
        $platformBalance = $isPlatformCredit
            ? round($balances['platform_credit_balance'] + $normalizedAmount, 2)
            : $balances['platform_credit_balance'];
        $ownBalance = $isPlatformCredit
            ? $balances['own_credit_balance']
            : round($balances['own_credit_balance'] + $normalizedAmount, 2);

        $wallet->forceFill([
            'balance_amount' => $newBalance,
            'meta' => [
                ...($wallet->meta ?? []),
                'platform_credit_balance' => $platformBalance,
                'own_credit_balance' => $ownBalance,
            ],
        ])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            $normalizedAmount,
            $description,
            [
                ...$meta,
                'funding_source' => $isPlatformCredit ? 'platform' : 'own',
                'platform_amount' => $isPlatformCredit ? $normalizedAmount : 0,
                'own_amount' => $isPlatformCredit ? 0 : $normalizedAmount,
            ],
            $reference,
            $newBalance,
        );
    }

    public function debitOwnCredit(
        Wallet $wallet,
        float $amount,
        string $type,
        ?string $description = null,
        array $meta = [],
        ?Model $reference = null,
    ): WalletTransaction {
        $normalizedAmount = round(abs($amount), 2);
        $currentBalance = round((float) $wallet->balance_amount, 2);
        $balances = $this->fundingBalances($wallet);

        if ($normalizedAmount > $balances['own_credit_balance']) {
            throw ValidationException::withMessages([
                'wallet' => ['Insufficient customer-paid wallet balance for this refund.'],
            ]);
        }

        $newBalance = round($currentBalance - $normalizedAmount, 2);
        $wallet->forceFill([
            'balance_amount' => $newBalance,
            'meta' => [
                ...($wallet->meta ?? []),
                'platform_credit_balance' => $balances['platform_credit_balance'],
                'own_credit_balance' => round($balances['own_credit_balance'] - $normalizedAmount, 2),
            ],
        ])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            -$normalizedAmount,
            $description,
            [
                ...$meta,
                'funding_source' => 'own',
                'platform_amount' => 0,
                'own_amount' => $normalizedAmount,
                'platform_percent' => 0,
                'own_percent' => 100,
            ],
            $reference,
            $newBalance,
        );
    }

    public function recordTransaction(
        Wallet $wallet,
        string $type,
        float $amount,
        ?string $description = null,
        array $meta = [],
        ?Model $reference = null,
        ?float $balanceAfter = null,
    ): WalletTransaction {
        return $wallet->transactions()->create([
            'user_id' => $wallet->user_id,
            'type' => $type,
            'amount' => round($amount, 2),
            'balance_after' => round($balanceAfter ?? (float) $wallet->balance_amount, 2),
            'currency' => $wallet->currency ?: Wallet::DEFAULT_CURRENCY,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'description' => $description,
            'meta' => $meta,
            'processed_at' => now(),
        ]);
    }

    /**
     * @return array{platform_amount: float, own_amount: float, platform_credit_balance_after: float, own_credit_balance_after: float}
     */
    protected function allocateDebitFunding(Wallet $wallet, float $amount): array
    {
        $balances = $this->fundingBalances($wallet);
        $platformAmount = min($amount, $balances['platform_credit_balance']);
        $ownAmount = round($amount - $platformAmount, 2);

        return [
            'platform_amount' => round($platformAmount, 2),
            'own_amount' => round($ownAmount, 2),
            'platform_credit_balance_after' => round($balances['platform_credit_balance'] - $platformAmount, 2),
            'own_credit_balance_after' => round(max(0, $balances['own_credit_balance'] - $ownAmount), 2),
        ];
    }

    /**
     * @return array{platform_credit_balance: float, own_credit_balance: float}
     */
    protected function fundingBalances(Wallet $wallet): array
    {
        $meta = $wallet->meta ?? [];

        if (array_key_exists('platform_credit_balance', $meta) || array_key_exists('own_credit_balance', $meta)) {
            return [
                'platform_credit_balance' => round((float) ($meta['platform_credit_balance'] ?? 0), 2),
                'own_credit_balance' => round((float) ($meta['own_credit_balance'] ?? 0), 2),
            ];
        }

        $platform = 0.0;
        $own = 0.0;

        WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->oldest('processed_at')
            ->oldest('id')
            ->get()
            ->each(function (WalletTransaction $transaction) use (&$platform, &$own): void {
                $amount = round((float) $transaction->amount, 2);
                $meta = $transaction->meta ?? [];

                if ($amount >= 0) {
                    if ($transaction->type === WalletTransaction::TYPE_WELCOME_BONUS || ($meta['funding_source'] ?? null) === 'platform') {
                        $platform = round($platform + $amount, 2);
                    } else {
                        $own = round($own + $amount, 2);
                    }

                    return;
                }

                $debit = abs($amount);
                $platformDebit = min($debit, $platform);
                $platform = round($platform - $platformDebit, 2);
                $own = round(max(0, $own - ($debit - $platformDebit)), 2);
            });

        $balance = round((float) $wallet->balance_amount, 2);
        $platform = min($platform, $balance);

        return [
            'platform_credit_balance' => round($platform, 2),
            'own_credit_balance' => round(max(0, $balance - $platform), 2),
        ];
    }

    protected function fundingSourceLabel(float $platformAmount, float $ownAmount): string
    {
        if ($platformAmount > 0 && $ownAmount > 0) {
            return 'mixed';
        }

        if ($platformAmount > 0) {
            return 'platform';
        }

        return 'own';
    }
}
