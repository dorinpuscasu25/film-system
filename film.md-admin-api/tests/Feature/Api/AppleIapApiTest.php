<?php

namespace Tests\Feature\Api;

use App\Models\AppleIapTransaction;
use App\Models\PersonalAccessToken;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AppleIapService;
use App\Services\IapCreditPackService;
use App\Services\WalletService;
use AppStoreServerLibrary\SignedDataVerifier\VerificationException;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class AppleIapApiTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_ID = 'md.filmoteca.ios.credits.100';

    public function test_local_testing_redemption_credits_the_apple_bucket_and_is_idempotent(): void
    {
        $user = $this->storefrontUser();
        $signed = $this->fakeSignedTransaction(['transactionId' => 'txn-1']);

        $record = app(AppleIapService::class)->redeem($user, $signed);

        $this->assertSame(AppleIapTransaction::STATUS_CREDITED, $record->status);
        $this->assertSame(100.0, (float) $record->credits_granted);
        $wallet = $user->wallet()->firstOrFail();
        // +20 is the automatic platform welcome bonus every new wallet gets — unrelated to Apple.
        $this->assertSame(120.0, (float) $wallet->balance_amount);
        $this->assertSame(100.0, (float) ($wallet->meta['apple_credit_balance'] ?? 0));
        $this->assertSame(0.0, (float) ($wallet->meta['own_credit_balance'] ?? 0));

        // Apple redelivers the same transaction (retry, or app relaunch replaying
        // Transaction.unfinished) — must not credit twice.
        $again = app(AppleIapService::class)->redeem($user, $signed);
        $this->assertSame($record->id, $again->id);
        $this->assertSame(120.0, (float) $user->wallet()->firstOrFail()->balance_amount);
        $this->assertSame(1, AppleIapTransaction::query()->count());
    }

    public function test_redeem_rejects_a_product_id_with_no_configured_credit_pack(): void
    {
        $user = $this->storefrontUser();
        $signed = $this->fakeSignedTransaction(['transactionId' => 'txn-2', 'productId' => 'not.a.real.product']);

        $this->expectException(ValidationException::class);
        app(AppleIapService::class)->redeem($user, $signed);
    }

    public function test_a_forged_sandbox_claim_without_a_real_signature_is_rejected_not_silently_downgraded(): void
    {
        // A payload claiming environment=Sandbox but signed with garbage never reaches the
        // LocalTesting fallback — it must fail verification outright, proving an attacker can't
        // dodge real signature checking just by relabelling the environment field.
        $header = $this->b64(['alg' => 'ES256', 'typ' => 'JWT']);
        $payload = $this->b64(['transactionId' => 'txn-3', 'environment' => 'Sandbox', 'productId' => self::PRODUCT_ID]);
        $forged = "{$header}.{$payload}.not-a-real-signature";

        $this->expectException(VerificationException::class);
        app(AppleIapService::class)->redeem($this->storefrontUser(), $forged);
    }

    public function test_sandbox_environment_is_rejected_for_accounts_not_flagged_as_test_accounts(): void
    {
        $user = $this->storefrontUser()->fresh();
        $this->assertFalse($user->is_test_account);

        $method = new ReflectionMethod(AppleIapService::class, 'assertEnvironmentAllowed');
        $method->setAccessible(true);

        $this->expectException(ValidationException::class);
        $method->invoke(app(AppleIapService::class), $user, \AppStoreServerLibrary\Models\Environment::SANDBOX);
    }

    public function test_sandbox_environment_is_allowed_once_the_account_is_flagged_for_testing(): void
    {
        $user = $this->storefrontUser();
        $user->forceFill(['is_test_account' => true])->save();

        $method = new ReflectionMethod(AppleIapService::class, 'assertEnvironmentAllowed');
        $method->setAccessible(true);
        $method->invoke(app(AppleIapService::class), $user, \AppStoreServerLibrary\Models\Environment::SANDBOX);

        $this->assertTrue(true); // no exception thrown
    }

    public function test_refund_notification_claws_back_the_apple_credit_and_marks_the_transaction_refunded(): void
    {
        $user = $this->storefrontUser();
        $signedTransaction = $this->fakeSignedTransaction(['transactionId' => 'txn-4']);
        app(AppleIapService::class)->redeem($user, $signedTransaction);
        $this->assertSame(100.0, (float) $user->wallet()->firstOrFail()->meta['apple_credit_balance']);

        $notification = $this->fakeSignedNotification([
            'notificationType' => 'REFUND',
            'data' => [
                'environment' => 'LocalTesting',
                'bundleId' => 'md.filmoteca.ios',
                'signedTransactionInfo' => $signedTransaction,
            ],
        ]);

        app(AppleIapService::class)->handleNotification($notification);

        $wallet = $user->wallet()->firstOrFail();
        // Back down to just the 20 MDL platform welcome bonus — the 100 Apple credits are gone.
        $this->assertSame(20.0, (float) $wallet->balance_amount);
        $this->assertSame(0.0, (float) $wallet->meta['apple_credit_balance']);
        $record = AppleIapTransaction::query()->where('transaction_id', 'txn-4')->firstOrFail();
        $this->assertSame(AppleIapTransaction::STATUS_REFUNDED, $record->status);
        $this->assertNotNull($record->refunded_at);

        // Idempotent: a redelivered REFUND notification must not clawback a second time.
        app(AppleIapService::class)->handleNotification($notification);
        $this->assertSame(20.0, (float) $user->wallet()->firstOrFail()->balance_amount);
    }

    public function test_refund_after_the_credit_was_already_spent_is_logged_not_thrown(): void
    {
        $user = $this->storefrontUser();
        $signedTransaction = $this->fakeSignedTransaction(['transactionId' => 'txn-5']);
        app(AppleIapService::class)->redeem($user, $signedTransaction);

        $wallet = $user->wallet()->firstOrFail();
        // Spend the whole balance (20 welcome + 100 Apple) so the apple_credit_balance bucket
        // specifically is left at zero before the refund notification arrives.
        app(WalletService::class)->debit($wallet, (float) $wallet->balance_amount, \App\Models\WalletTransaction::TYPE_PURCHASE, 'spent it all');
        $this->assertSame(0.0, (float) $wallet->fresh()->balance_amount);

        $notification = $this->fakeSignedNotification([
            'notificationType' => 'REFUND',
            'data' => ['environment' => 'LocalTesting', 'bundleId' => 'md.filmoteca.ios', 'signedTransactionInfo' => $signedTransaction],
        ]);

        // Should not throw even though there's nothing left in the apple bucket to claw back.
        app(AppleIapService::class)->handleNotification($notification);
        $record = AppleIapTransaction::query()->where('transaction_id', 'txn-5')->firstOrFail();
        $this->assertSame(AppleIapTransaction::STATUS_REFUNDED, $record->status);
    }

    public function test_redeem_endpoint_credits_the_wallet_over_http(): void
    {
        $user = $this->storefrontUser();
        [, $token] = PersonalAccessToken::issue($user, 'apple-iap-test');
        $signed = $this->fakeSignedTransaction(['transactionId' => 'txn-6']);

        $this->postJson('/api/v1/storefront/wallet/apple-iap/redeem', [
            'signed_transaction' => $signed,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('status', AppleIapTransaction::STATUS_CREDITED)
            ->assertJsonPath('credits_granted', 100)
            ->assertJsonPath('wallet.balance_amount', 120);
    }

    public function test_credit_pack_settings_are_admin_configurable(): void
    {
        PlatformSetting::setValue(IapCreditPackService::SETTINGS_KEY, [
            'packs' => [['product_id' => 'custom.pack', 'credits_mdl' => 42, 'sort_order' => 1]],
        ]);

        $credits = app(IapCreditPackService::class)->creditsForProduct('custom.pack');
        $this->assertSame(42.0, $credits);
        $this->assertNull(app(IapCreditPackService::class)->creditsForProduct(self::PRODUCT_ID));
        // Placeholder packs beyond the saved list's length must not survive an index-wise merge.
        $this->assertNull(app(IapCreditPackService::class)->creditsForProduct('md.filmoteca.ios.credits.500'));
        $this->assertCount(1, app(IapCreditPackService::class)->packs());
    }

    public function test_packs_endpoint_lists_the_configured_catalog_for_the_app(): void
    {
        PlatformSetting::setValue(IapCreditPackService::SETTINGS_KEY, [
            'commission_rate' => 0.15,
            'packs' => [
                ['product_id' => 'pack.big', 'credits_mdl' => 500, 'apple_price_usd' => 33.99, 'sort_order' => 2],
                ['product_id' => 'pack.small', 'credits_mdl' => 100, 'apple_price_usd' => 6.99, 'sort_order' => 1],
            ],
        ]);
        $user = $this->storefrontUser();
        [, $token] = PersonalAccessToken::issue($user, 'apple-iap-test');

        $this->getJson('/api/v1/storefront/wallet/apple-iap/packs', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonCount(2, 'packs')
            ->assertJsonPath('packs.0.product_id', 'pack.small')
            ->assertJsonPath('packs.0.credits_mdl', 100)
            ->assertJsonMissingPath('packs.0.apple_price_usd');
    }

    private function storefrontUser(): User
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create(['status' => 'active']);
        $viewerRoleId = \App\Models\Role::query()->where('is_default', true)->value('id');
        $user->roles()->sync(array_filter([$viewerRoleId]));

        return $user->fresh();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function b64(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    private function fakeSignedTransaction(array $overrides = []): string
    {
        $header = $this->b64(['alg' => 'ES256', 'typ' => 'JWT']);
        $payload = $this->b64([
            'transactionId' => 'txn-default',
            'originalTransactionId' => $overrides['transactionId'] ?? 'txn-default',
            'bundleId' => 'md.filmoteca.ios',
            'productId' => self::PRODUCT_ID,
            'purchaseDate' => now()->getTimestampMs(),
            'environment' => 'LocalTesting',
            'currency' => 'USD',
            'price' => 599,
            ...$overrides,
        ]);

        return "{$header}.{$payload}.local-testing-signature-not-verified";
    }

    private function fakeSignedNotification(array $overrides): string
    {
        $header = $this->b64(['alg' => 'ES256', 'typ' => 'JWT']);
        $payload = $this->b64([
            'notificationType' => 'REFUND',
            'notificationUUID' => (string) \Illuminate\Support\Str::uuid(),
            'signedDate' => now()->getTimestampMs(),
            ...$overrides,
        ]);

        return "{$header}.{$payload}.local-testing-signature-not-verified";
    }
}
