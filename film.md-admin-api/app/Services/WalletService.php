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

    public function ensureWallet(User $user, bool $grantRegistrationCredit = true): Wallet
    {
        $existingWallet = $user->wallet()->first();
        if ($existingWallet !== null) {
            return $existingWallet;
        }

        try {
            return DB::transaction(function () use ($user, $grantRegistrationCredit): Wallet {
                $wallet = $user->wallet()->first();
                if ($wallet !== null) {
                    return $wallet;
                }

                $registrationCredit = $grantRegistrationCredit
                    ? $this->registrationCredit->resolveForRegistration($user->created_at)
                    : ['enabled' => false, 'amount' => 0.0, 'campaign' => null];
                $welcomeCredit = $registrationCredit['enabled'] ? $registrationCredit['amount'] : 0.0;

                $wallet = $user->wallet()->create([
                    'currency' => Wallet::DEFAULT_CURRENCY,
                    'balance_amount' => $welcomeCredit,
                    'meta' => [
                        'source' => 'system',
                        'initial_credit' => $welcomeCredit,
                        'platform_credit_balance' => $welcomeCredit,
                        'own_credit_balance' => 0,
                        'apple_credit_balance' => 0,
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
                'apple_credit_balance' => $funding['apple_credit_balance_after'],
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
                'apple_amount' => $funding['apple_amount'],
                'own_amount' => $funding['own_amount'],
                'platform_percent' => $normalizedAmount > 0 ? round($funding['platform_amount'] / $normalizedAmount * 100, 2) : 0,
                'apple_percent' => $normalizedAmount > 0 ? round($funding['apple_amount'] / $normalizedAmount * 100, 2) : 0,
                'own_percent' => $normalizedAmount > 0 ? round($funding['own_amount'] / $normalizedAmount * 100, 2) : 0,
                'funding_source' => $this->fundingSourceLabel($funding['platform_amount'], $funding['apple_amount'], $funding['own_amount']),
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
        $fundingSource = $type === WalletTransaction::TYPE_WELCOME_BONUS
            ? 'platform'
            : (($meta['funding_source'] ?? null) === 'apple' ? 'apple' : (($meta['funding_source'] ?? null) === 'platform' ? 'platform' : 'own'));

        $bucketKey = match ($fundingSource) {
            'platform' => 'platform_credit_balance',
            'apple' => 'apple_credit_balance',
            default => 'own_credit_balance',
        };

        $wallet->forceFill([
            'balance_amount' => $newBalance,
            'meta' => [
                ...($wallet->meta ?? []),
                'platform_credit_balance' => $balances['platform_credit_balance'],
                'apple_credit_balance' => $balances['apple_credit_balance'],
                'own_credit_balance' => $balances['own_credit_balance'],
                $bucketKey => round(($balances[$bucketKey] ?? 0) + $normalizedAmount, 2),
            ],
        ])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            $normalizedAmount,
            $description,
            [
                ...$meta,
                'funding_source' => $fundingSource,
                'platform_amount' => $fundingSource === 'platform' ? $normalizedAmount : 0,
                'apple_amount' => $fundingSource === 'apple' ? $normalizedAmount : 0,
                'own_amount' => $fundingSource === 'own' ? $normalizedAmount : 0,
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
        return $this->debitBucket($wallet, 'own_credit_balance', 'Insufficient customer-paid wallet balance for this refund.', $amount, $type, $description, $meta, $reference);
    }

    /**
     * Claws back an Apple IAP credit purchase on a REFUND/REVOKE server notification. Only ever
     * pulls from the apple_credit_balance bucket, never touching platform or own-sourced credit.
     */
    public function debitAppleCredit(
        Wallet $wallet,
        float $amount,
        string $type,
        ?string $description = null,
        array $meta = [],
        ?Model $reference = null,
    ): WalletTransaction {
        return $this->debitBucket($wallet, 'apple_credit_balance', 'Insufficient Apple-sourced wallet balance for this clawback.', $amount, $type, $description, $meta, $reference);
    }

    private function debitBucket(
        Wallet $wallet,
        string $bucketKey,
        string $insufficientMessage,
        float $amount,
        string $type,
        ?string $description,
        array $meta,
        ?Model $reference,
    ): WalletTransaction {
        $normalizedAmount = round(abs($amount), 2);
        $currentBalance = round((float) $wallet->balance_amount, 2);
        $balances = $this->fundingBalances($wallet);
        $bucketBalance = $balances[$bucketKey] ?? 0.0;

        if ($normalizedAmount > $bucketBalance) {
            throw ValidationException::withMessages(['wallet' => [$insufficientMessage]]);
        }

        $fundingSource = str_replace('_credit_balance', '', $bucketKey);
        $newBalance = round($currentBalance - $normalizedAmount, 2);
        $wallet->forceFill([
            'balance_amount' => $newBalance,
            'meta' => [
                ...($wallet->meta ?? []),
                'platform_credit_balance' => $balances['platform_credit_balance'],
                'apple_credit_balance' => $balances['apple_credit_balance'],
                'own_credit_balance' => $balances['own_credit_balance'],
                $bucketKey => round($bucketBalance - $normalizedAmount, 2),
            ],
        ])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            -$normalizedAmount,
            $description,
            [
                ...$meta,
                'funding_source' => $fundingSource,
                'platform_amount' => 0,
                'apple_amount' => $fundingSource === 'apple' ? $normalizedAmount : 0,
                'own_amount' => $fundingSource === 'own' ? $normalizedAmount : 0,
                'platform_percent' => 0,
                'apple_percent' => $fundingSource === 'apple' ? 100 : 0,
                'own_percent' => $fundingSource === 'own' ? 100 : 0,
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
     * Consumption order: platform (free bonus) first, then Apple-sourced, then own (web top-up)
     * last — spend the credit with the fewest strings attached before the credit that's most
     * useful to keep around (own-sourced is what refunds pull from).
     *
     * @return array{platform_amount: float, apple_amount: float, own_amount: float, platform_credit_balance_after: float, apple_credit_balance_after: float, own_credit_balance_after: float}
     */
    protected function allocateDebitFunding(Wallet $wallet, float $amount): array
    {
        $balances = $this->fundingBalances($wallet);
        $platformAmount = min($amount, $balances['platform_credit_balance']);
        $remaining = round($amount - $platformAmount, 2);
        $appleAmount = min($remaining, $balances['apple_credit_balance']);
        $ownAmount = round($remaining - $appleAmount, 2);

        return [
            'platform_amount' => round($platformAmount, 2),
            'apple_amount' => round($appleAmount, 2),
            'own_amount' => round($ownAmount, 2),
            'platform_credit_balance_after' => round($balances['platform_credit_balance'] - $platformAmount, 2),
            'apple_credit_balance_after' => round($balances['apple_credit_balance'] - $appleAmount, 2),
            'own_credit_balance_after' => round(max(0, $balances['own_credit_balance'] - $ownAmount), 2),
        ];
    }

    /**
     * @return array{platform_credit_balance: float, apple_credit_balance: float, own_credit_balance: float}
     */
    protected function fundingBalances(Wallet $wallet): array
    {
        $meta = $wallet->meta ?? [];

        if (array_key_exists('platform_credit_balance', $meta) || array_key_exists('own_credit_balance', $meta)) {
            return [
                'platform_credit_balance' => round((float) ($meta['platform_credit_balance'] ?? 0), 2),
                'apple_credit_balance' => round((float) ($meta['apple_credit_balance'] ?? 0), 2),
                'own_credit_balance' => round((float) ($meta['own_credit_balance'] ?? 0), 2),
            ];
        }

        $platform = 0.0;
        $apple = 0.0;
        $own = 0.0;

        WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->oldest('processed_at')
            ->oldest('id')
            ->get()
            ->each(function (WalletTransaction $transaction) use (&$platform, &$apple, &$own): void {
                $amount = round((float) $transaction->amount, 2);
                $meta = $transaction->meta ?? [];
                $source = $transaction->type === WalletTransaction::TYPE_WELCOME_BONUS
                    ? 'platform'
                    : ($meta['funding_source'] ?? 'own');

                if ($amount >= 0) {
                    match ($source) {
                        'platform' => $platform = round($platform + $amount, 2),
                        'apple' => $apple = round($apple + $amount, 2),
                        default => $own = round($own + $amount, 2),
                    };

                    return;
                }

                $debit = abs($amount);
                $platformDebit = min($debit, $platform);
                $platform = round($platform - $platformDebit, 2);
                $debit = round($debit - $platformDebit, 2);
                $appleDebit = min($debit, $apple);
                $apple = round($apple - $appleDebit, 2);
                $own = round(max(0, $own - ($debit - $appleDebit)), 2);
            });

        $balance = round((float) $wallet->balance_amount, 2);
        $platform = min($platform, $balance);
        $apple = min($apple, round($balance - $platform, 2));

        return [
            'platform_credit_balance' => round($platform, 2),
            'apple_credit_balance' => round(max(0, $apple), 2),
            'own_credit_balance' => round(max(0, $balance - $platform - $apple), 2),
        ];
    }

    protected function fundingSourceLabel(float $platformAmount, float $appleAmount, float $ownAmount): string
    {
        $usedBuckets = collect(['platform' => $platformAmount, 'apple' => $appleAmount, 'own' => $ownAmount])
            ->filter(fn (float $amount): bool => $amount > 0);

        if ($usedBuckets->count() > 1) {
            return 'mixed';
        }

        return $usedBuckets->keys()->first() ?? 'own';
    }
}
