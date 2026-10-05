<?php

namespace App\Services;

use App\Models\AppleIapTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use AppStoreServerLibrary\AppStoreServerAPIClient;
use AppStoreServerLibrary\Models\ConsumptionRequest;
use AppStoreServerLibrary\Models\DeliveryStatus;
use AppStoreServerLibrary\Models\Environment;
use AppStoreServerLibrary\Models\JWSTransactionDecodedPayload;
use AppStoreServerLibrary\Models\NotificationTypeV2;
use AppStoreServerLibrary\Models\ResponseBodyV2DecodedPayload;
use AppStoreServerLibrary\SignedDataVerifier;
use AppStoreServerLibrary\SignedDataVerifier\VerificationException;
use AppStoreServerLibrary\SignedDataVerifier\VerificationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Redeems Apple In-App Purchase credit packs and reconciles App Store Server Notifications
 * (refunds, revocations, consumption requests). See docs/ios-in-app-purchase-audit.md for the
 * business model this implements: 1 credit = 1 MDL everywhere, iOS sells credit *packs* as
 * StoreKit consumables (never the film price itself) to cover Apple's commission.
 *
 * Fraud gate (the reason this class exists in this exact shape): a signedTransaction/signedPayload
 * carries an `environment` field that is only trustworthy once cryptographic verification has
 * passed. Production and Sandbox are both genuinely Apple-signed and get full chain + OCSP
 * verification; Xcode/LocalTesting payloads are — by Apple's own library design — NEVER
 * cryptographically checked, so they are only ever accepted outside of our own production
 * environment, and Sandbox is only credited for accounts explicitly flagged `is_test_account`.
 * Getting any of this gating wrong means anyone can mint unlimited free wallet credit.
 */
class AppleIapService
{
    public function __construct(
        protected WalletService $wallets,
        protected IapCreditPackService $creditPacks,
    ) {}

    /**
     * @throws VerificationException|ValidationException
     */
    public function redeem(User $user, string $signedTransaction): AppleIapTransaction
    {
        $decoded = $this->verifyTransaction($signedTransaction);
        $this->assertEnvironmentAllowed($user, $decoded->getEnvironment());

        $transactionId = $decoded->getTransactionId();
        if ($transactionId === null || $transactionId === '') {
            throw ValidationException::withMessages([
                'signed_transaction' => ['Chitanța Apple nu conține un identificator de tranzacție.'],
            ]);
        }

        return DB::transaction(function () use ($user, $signedTransaction, $decoded, $transactionId): AppleIapTransaction {
            $existing = AppleIapTransaction::query()->where('transaction_id', $transactionId)->lockForUpdate()->first();
            if ($existing !== null) {
                // Apple retries delivery of the same transaction; StoreKit may also call us twice
                // (e.g. app relaunch replaying Transaction.unfinished). Never credit twice.
                return $existing;
            }

            $productId = $decoded->getProductId() ?? 'unknown';
            $credits = $this->creditPacks->creditsForProduct($productId);

            $record = AppleIapTransaction::query()->create([
                'transaction_id' => $transactionId,
                'original_transaction_id' => $decoded->getOriginalTransactionId(),
                'user_id' => $user->id,
                'product_id' => $productId,
                'app_account_token' => $decoded->getAppAccountToken(),
                'credits_granted' => $credits ?? 0,
                'apple_price' => $decoded->getPrice(),
                'apple_currency' => $decoded->getCurrency(),
                'environment' => $decoded->getEnvironment()?->value ?? 'Unknown',
                'status' => AppleIapTransaction::STATUS_PENDING,
                'signed_transaction' => $signedTransaction,
                'decoded_payload' => $this->summarize($decoded),
            ]);

            if ($credits === null) {
                $record->forceFill(['status' => AppleIapTransaction::STATUS_REJECTED])->save();
                throw ValidationException::withMessages([
                    'signed_transaction' => ["Produsul \"{$productId}\" nu este configurat în catalogul de pachete de credit."],
                ]);
            }

            $this->wallets->ensureWallet($user);
            $wallet = $this->wallets->lockWallet($user);
            $this->wallets->credit(
                $wallet,
                $credits,
                WalletTransaction::TYPE_TOP_UP,
                "Alimentare prin App Store ({$productId})",
                [
                    'funding_source' => 'apple',
                    'apple_transaction_id' => $transactionId,
                    'apple_environment' => $decoded->getEnvironment()?->value,
                ],
                $record,
            );

            $record->forceFill([
                'wallet_id' => $wallet->id,
                'status' => AppleIapTransaction::STATUS_CREDITED,
                'credited_at' => now(),
            ])->save();

            return $record;
        });
    }

    /**
     * Entry point for `POST webhooks/apple/notifications` (App Store Server Notifications V2).
     */
    public function handleNotification(string $signedPayload): void
    {
        $decoded = $this->verifyNotificationPayload($signedPayload);
        $data = $decoded->getData();
        $signedTransactionInfo = $data?->getSignedTransactionInfo();
        if ($signedTransactionInfo === null) {
            return; // Summary/external-purchase/renewal-only notifications don't concern consumables.
        }

        $transaction = $this->verifyTransaction($signedTransactionInfo);
        $record = AppleIapTransaction::query()->where('transaction_id', $transaction->getTransactionId())->first();
        if ($record === null) {
            Log::channel('payments')->info('Apple notification for an unknown transaction', [
                'transaction_id' => $transaction->getTransactionId(), 'type' => $decoded->getNotificationType()?->value,
            ]);

            return;
        }

        match ($decoded->getNotificationType()) {
            NotificationTypeV2::REFUND, NotificationTypeV2::REVOKE => $this->clawback($record),
            NotificationTypeV2::CONSUMPTION_REQUEST => $this->respondToConsumptionRequest($record, $data->getEnvironment() ?? $transaction->getEnvironment()),
            default => null,
        };
    }

    protected function clawback(AppleIapTransaction $record): void
    {
        DB::transaction(function () use ($record): void {
            $locked = AppleIapTransaction::query()->whereKey($record->id)->lockForUpdate()->first();
            if ($locked === null || $locked->refunded_at !== null || $locked->status !== AppleIapTransaction::STATUS_CREDITED) {
                return; // already reconciled, or was never credited in the first place.
            }

            $wallet = $locked->wallet_id !== null ? Wallet::query()->whereKey($locked->wallet_id)->lockForUpdate()->first() : null;
            if ($wallet === null) {
                $locked->forceFill(['status' => AppleIapTransaction::STATUS_REFUNDED, 'refunded_at' => now()])->save();

                return;
            }

            try {
                $this->wallets->debitAppleCredit(
                    $wallet,
                    (float) $locked->credits_granted,
                    WalletTransaction::TYPE_ADJUSTMENT,
                    'Apple refund/revocation clawback',
                    ['apple_transaction_id' => $locked->transaction_id, 'reason' => 'apple_refund'],
                    $locked,
                );
            } catch (ValidationException) {
                // The credit was already spent on a purchase — nothing left in that bucket to
                // claw back. Apple still processed the refund on their side regardless; flag for
                // manual review rather than silently under- or over-charging the wallet.
                Log::channel('payments')->warning('Apple refund could not be fully clawed back — credit already spent', [
                    'transaction_id' => $locked->transaction_id, 'user_id' => $locked->user_id,
                ]);
            }

            $locked->forceFill(['status' => AppleIapTransaction::STATUS_REFUNDED, 'refunded_at' => now()])->save();
        });
    }

    /**
     * Best-effort: tells Apple the credits were delivered, which factors into how Apple resolves
     * a pending refund request. Not required for compliance — Apple decides refunds on its own
     * either way — so failures here are logged and swallowed rather than surfaced.
     */
    protected function respondToConsumptionRequest(AppleIapTransaction $record, ?Environment $environment): void
    {
        $signingKey = (string) config('services.apple.signing_key');
        $keyId = config('services.apple.key_id');
        $issuerId = config('services.apple.issuer_id');
        if ($signingKey === '' || $keyId === null || $issuerId === null || $environment === null || $environment === Environment::XCODE) {
            return;
        }

        try {
            $client = new AppStoreServerAPIClient(
                signingKey: $signingKey,
                keyId: (string) $keyId,
                issuerId: (string) $issuerId,
                bundleId: (string) config('services.apple.bundle_id'),
                environment: $environment,
            );
            $client->sendConsumptionInformation($record->transaction_id, new ConsumptionRequest(
                customerConsented: false,
                sampleContentProvided: false,
                deliveryStatus: DeliveryStatus::DELIVERED,
                consumptionPercentage: null,
                refundPreference: null,
            ));
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('Apple consumption request response failed', [
                'transaction_id' => $record->transaction_id, 'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function assertEnvironmentAllowed(User $user, ?Environment $environment): void
    {
        if ($environment === Environment::PRODUCTION) {
            return;
        }

        if ($environment === Environment::SANDBOX) {
            if (! $user->is_test_account) {
                throw ValidationException::withMessages([
                    'signed_transaction' => ['Această chitanță este din mediul Sandbox al Apple și contul tău nu este marcat ca cont de test.'],
                ]);
            }

            return;
        }

        // Xcode / LocalTesting: the library never cryptographically verifies these, so trusting
        // them at all is conditional on our own server not being production — enforced already
        // inside verifyTransaction()/verifyNotificationPayload() before we ever reach here. This
        // is a second, redundant check on purpose.
        if (app()->environment('production')) {
            throw ValidationException::withMessages([
                'signed_transaction' => ['Chitanța Apple nu a putut fi verificată.'],
            ]);
        }
    }

    /**
     * @throws VerificationException
     */
    protected function verifyTransaction(string $signedTransaction): JWSTransactionDecodedPayload
    {
        /** @var JWSTransactionDecodedPayload */
        return $this->verifyWithEnvironmentRouting(
            $signedTransaction,
            fn (SignedDataVerifier $verifier) => $verifier->verifyAndDecodeSignedTransaction($signedTransaction),
        );
    }

    /**
     * @throws VerificationException
     */
    protected function verifyNotificationPayload(string $signedPayload): ResponseBodyV2DecodedPayload
    {
        /** @var ResponseBodyV2DecodedPayload */
        return $this->verifyWithEnvironmentRouting(
            $signedPayload,
            fn (SignedDataVerifier $verifier) => $verifier->verifyAndDecodeNotification($signedPayload),
        );
    }

    /**
     * Picks exactly one verifier — matching the payload's own (unverified) `environment` claim —
     * and runs the real check through it. That peek is never itself treated as proof of anything:
     * for Production/Sandbox it only decides which cryptographic chain to check against, and the
     * verifier's own post-check (`decoded environment === this verifier's environment`) still
     * rejects a payload that lied about which one it is. For Xcode/LocalTesting there is no
     * cryptographic check at all — by the library's own design — so trusting it is gated purely
     * on our own server not running in production.
     *
     * (An earlier version of this tried Production, then Sandbox, then LocalTesting in sequence.
     * That doesn't work: a LocalTesting payload isn't signed by Apple at all, so the Sandbox
     * attempt fails with VERIFICATION_FAILURE, not INVALID_ENVIRONMENT, and the cascade never
     * reaches LocalTesting. Routing by the claimed environment up front avoids that dead end.)
     *
     * @throws VerificationException
     */
    protected function verifyWithEnvironmentRouting(string $signedData, callable $attempt): mixed
    {
        $claimed = $this->peekEnvironment($signedData);

        $verifier = match ($claimed) {
            Environment::XCODE, Environment::LOCAL_TESTING => app()->environment('production')
                ? null
                : $this->verifierFor(Environment::LOCAL_TESTING),
            Environment::PRODUCTION => $this->verifierFor(Environment::PRODUCTION),
            Environment::SANDBOX => $this->verifierFor(Environment::SANDBOX),
            default => null,
        };

        if ($verifier === null) {
            throw new VerificationException(VerificationStatus::INVALID_ENVIRONMENT);
        }

        return $attempt($verifier);
    }

    /**
     * Unverified — used only to pick which verifier to route to, never trusted as fact. See
     * verifyWithEnvironmentRouting() for why that split is safe.
     *
     * A signedTransaction has `environment` at the top level; a notification's signedPayload
     * (ResponseBodyV2) nests it under `data.environment` (or `summary.environment`) instead —
     * both shapes are checked here.
     */
    protected function peekEnvironment(string $signedData): ?Environment
    {
        $segments = explode('.', $signedData);
        if (count($segments) !== 3) {
            return null;
        }

        $decoded = json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true);
        if (! is_array($decoded)) {
            return null;
        }

        $raw = $decoded['environment']
            ?? $decoded['data']['environment']
            ?? $decoded['summary']['environment']
            ?? null;

        return is_string($raw) ? Environment::tryFrom($raw) : null;
    }

    protected function verifierFor(Environment $environment): ?SignedDataVerifier
    {
        $bundleId = (string) config('services.apple.bundle_id');
        $appAppleId = config('services.apple.app_apple_id');

        if ($environment === Environment::PRODUCTION && $appAppleId === null) {
            // Not configured yet — no numeric App Store Connect app ID exists until the app is
            // created there. Production redemptions simply aren't possible until then.
            return null;
        }

        return new SignedDataVerifier(
            rootCertificates: $environment === Environment::LOCAL_TESTING ? [] : $this->rootCertificates(),
            enableOnlineChecks: $environment !== Environment::LOCAL_TESTING && (bool) config('services.apple.enable_online_checks', true),
            environment: $environment,
            bundleId: $bundleId,
            appAppleId: $environment === Environment::PRODUCTION ? (int) $appAppleId : null,
        );
    }

    /**
     * @return string[] Raw DER bytes, one per configured root certificate file.
     */
    protected function rootCertificates(): array
    {
        return collect((array) config('services.apple.root_certificate_paths', []))
            ->filter(fn (string $path): bool => $path !== '' && is_readable($path))
            ->map(fn (string $path): string => (string) file_get_contents($path))
            ->values()
            ->all();
    }

    /**
     * Curated subset kept for audit/debugging — not a full serialization of the decoded payload.
     *
     * @return array<string, mixed>
     */
    protected function summarize(JWSTransactionDecodedPayload $decoded): array
    {
        return [
            'transaction_id' => $decoded->getTransactionId(),
            'original_transaction_id' => $decoded->getOriginalTransactionId(),
            'product_id' => $decoded->getProductId(),
            'purchase_date' => $decoded->getPurchaseDate(),
            'environment' => $decoded->getEnvironment()?->value,
            'type' => $decoded->getType()?->value,
            'in_app_ownership_type' => $decoded->getInAppOwnershipType()?->value,
            'storefront' => $decoded->getStorefront(),
            'currency' => $decoded->getCurrency(),
            'price' => $decoded->getPrice(),
            'app_account_token' => $decoded->getAppAccountToken(),
            'signed_date' => $decoded->getSignedDate(),
        ];
    }
}
