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

                $wallet = $user->wallet()->create([
                    'currency' => Wallet::DEFAULT_CURRENCY,
                    'balance_amount' => Wallet::DEFAULT_WELCOME_CREDIT,
                    'meta' => [
                        'source' => 'system',
                        'initial_credit' => Wallet::DEFAULT_WELCOME_CREDIT,
                    ],
                ]);

                $this->recordTransaction(
                    $wallet,
                    WalletTransaction::TYPE_WELCOME_BONUS,
                    Wallet::DEFAULT_WELCOME_CREDIT,
                    'Welcome credit',
                    [
                        'reason' => 'automatic_welcome_bonus',
                    ],
                );

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

        $newBalance = round($currentBalance - $normalizedAmount, 2);
        $wallet->forceFill(['balance_amount' => $newBalance])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            -$normalizedAmount,
            $description,
            $meta,
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

        $wallet->forceFill(['balance_amount' => $newBalance])->save();

        return $this->recordTransaction(
            $wallet,
            $type,
            $normalizedAmount,
            $description,
            $meta,
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
}
