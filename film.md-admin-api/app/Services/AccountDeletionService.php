<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Account deletion required by App Store Review Guideline 5.1.1(v).
 *
 * Deletion is immediate and irreversible from the customer's perspective: the
 * account is locked, every session is revoked and all personal data is wiped in
 * the same transaction. The user row itself is kept and anonymised rather than
 * hard-deleted, because most commerce tables reference it with
 * `cascadeOnDelete()` — removing the row would destroy wallet transactions,
 * entitlements, top-ups and refunds that we are legally required to retain.
 *
 * Retained (anonymised through the user row): wallets, wallet_transactions,
 * content_entitlements, payment_top_ups, payment_refunds, playback_sessions,
 * audit_logs.
 */
class AccountDeletionService
{
    public const STATUS_DELETED = 'deleted';

    public function __construct(
        protected WalletService $wallets,
        protected AuditLogService $auditLog,
    ) {}

    /**
     * @return array{deleted_at: string, forfeited_balance: float, currency: string}
     */
    public function delete(User $user, ?string $reason = null, ?Request $request = null): array
    {
        return DB::transaction(function () use ($user, $reason, $request): array {
            $entitlementsCount = $user->entitlements()->count();
            $forfeited = $this->forfeitWalletBalance($user);

            // Recorded before anonymisation so the audit trail keeps the real actor.
            $this->auditLog->record(
                'account.deleted',
                'user',
                $user->getKey(),
                [
                    'forfeited_balance' => $forfeited['amount'],
                    'currency' => $forfeited['currency'],
                    'entitlements_retained' => $entitlementsCount,
                    'reason' => $reason,
                ],
                $user,
                $request,
            );

            $this->purgePersonalData($user);
            $this->anonymizeUser($user, $reason);

            return [
                'deleted_at' => $user->deleted_at->toIso8601String(),
                'forfeited_balance' => $forfeited['amount'],
                'currency' => $forfeited['currency'],
            ];
        });
    }

    /**
     * Zeroes the wallet through a regular adjustment transaction so the ledger
     * stays balanced — the outstanding liability is extinguished, not erased.
     *
     * @return array{amount: float, currency: string}
     */
    protected function forfeitWalletBalance(User $user): array
    {
        $wallet = $user->wallet()->lockForUpdate()->first();

        if ($wallet === null) {
            return ['amount' => 0.0, 'currency' => Wallet::DEFAULT_CURRENCY];
        }

        $currency = $wallet->currency ?: Wallet::DEFAULT_CURRENCY;
        $balance = round((float) $wallet->balance_amount, 2);

        if ($balance <= 0) {
            return ['amount' => 0.0, 'currency' => $currency];
        }

        $this->wallets->debit(
            $wallet,
            $balance,
            WalletTransaction::TYPE_ADJUSTMENT,
            'Wallet balance forfeited on account deletion',
            ['reason' => 'account_deleted'],
        );

        return ['amount' => $balance, 'currency' => $currency];
    }

    protected function purgePersonalData(User $user): void
    {
        $userId = $user->getKey();
        $email = $user->email;

        // Sessions and credentials — the account must be unreachable immediately.
        $user->apiTokens()->delete();
        DB::table('email_verification_codes')->where('user_id', $userId)->delete();
        DB::table('device_auth_codes')->where('user_id', $userId)->update(['user_id' => null]);

        // Profiles, their favourites and any parental PIN.
        $profileIds = DB::table('account_profiles')->where('user_id', $userId)->pluck('id');
        if ($profileIds->isNotEmpty()) {
            DB::table('account_profile_favorite_content')
                ->whereIn('account_profile_id', $profileIds)
                ->delete();
        }
        DB::table('account_profiles')->where('user_id', $userId)->delete();

        // Billing details.
        DB::table('billing_addresses')->where('user_id', $userId)->delete();

        // Public and behavioural traces.
        DB::table('content_reviews')->where('user_id', $userId)->delete();
        DB::table('watch_progress')->where('user_id', $userId)->delete();
        DB::table('watch_history')->where('user_id', $userId)->delete();
        DB::table('watch_party_chat_messages')->where('user_id', $userId)->delete();
        DB::table('watch_party_participants')->where('user_id', $userId)->delete();

        if (filled($email)) {
            DB::table('newsletter_subscribers')->where('email', $email)->delete();
        }

        // Staff/creator scoping — a deleted account keeps no access grants.
        DB::table('user_content_accesses')->where('user_id', $userId)->delete();
        DB::table('role_user')->where('user_id', $userId)->delete();
    }

    protected function anonymizeUser(User $user, ?string $reason): void
    {
        $now = now();
        $placeholder = sprintf('deleted-%d-%s@deleted.invalid', $user->getKey(), Str::lower(Str::random(12)));

        $user->forceFill([
            'name' => 'Cont șters',
            'email' => $placeholder,
            'password' => Hash::make(Str::random(64)),
            'avatar_url' => null,
            'payment_phone' => null,
            'preferred_locale' => $user->preferred_locale,
            'status' => self::STATUS_DELETED,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'email_verified_at' => null,
            'deleted_at' => $now,
            'deletion_reason' => filled($reason) ? Str::limit((string) $reason, 490, '') : null,
        ])->save();
    }
}
