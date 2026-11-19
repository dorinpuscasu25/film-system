<?php

namespace Tests\Feature\Api;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AccountProfileService;
use App\Services\Payments\Mia\MiaPaymentService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Wallet top-ups paid by MIA instant payment (Victoriabank dynamic QR). */
class MiaTopUpTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://mia.test';

    private const REFERENCE = 'pacs.008.001.10|2026-11-18|VICBMD2X|VICBMD2XAXXX261118000000000000001';

    private const TRANSACTION_ID = 'VICBMD2XAXXX261118000000000000001';

    protected User $user;

    protected string $token;

    /** @var \OpenSSLAsymmetricKey */
    protected $bankKey;

    protected array $statusResponse = ['uuid' => 'ext-1', 'status' => 'Active', 'payments' => []];

    protected int $refundHttpStatus = 204;

    protected array $reconciliationRows = [];

    /** New accounts may start with a promotional credit; assertions are relative to it. */
    protected float $startingBalance = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bankKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $certificatePath = tempnam(sys_get_temp_dir(), 'vbca');
        file_put_contents($certificatePath, openssl_pkey_get_details($this->bankKey)['key']);

        config([
            'services.mia' => [
                'enabled' => true,
                'base_url' => self::BASE,
                'username' => 'merchant',
                'password' => 'secret',
                'iban' => 'MD00VI000000000000000MDL',
                'dba' => 'Filmoteca',
                'qr_ttl_minutes' => 15,
                'timeout' => 5,
                'signal_certificates' => [$certificatePath],
            ],
        ]);

        Http::fake(function (HttpRequest $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $path === '/identity/token' => Http::response([
                    'accessToken' => 'access-token', 'tokenType' => 'bearer', 'expiresIn' => 3600,
                    'refreshToken' => 'refresh-token', 'refreshExpiresIn' => 18000,
                ]),
                $path === '/api/v1/qr' && $request->method() === 'POST' => Http::response([
                    'qrHeaderUUID' => 'hdr-1',
                    'qrExtensionUUID' => 'ext-1',
                    'qrAsText' => 'https://mia-qr-test.bnm.md/1/e/BNM/VIChdr1',
                    'qrAsImage' => base64_encode('png-bytes'),
                ]),
                $path === '/api/v1/qr/hdr-1' && $request->method() === 'DELETE' => Http::response(null, 204),
                $path === '/api/v1/qr-extensions/ext-1/status' => Http::response($this->statusResponse),
                str_starts_with($path, '/api/v1/transaction/') => Http::response(null, $this->refundHttpStatus),
                $path === '/api/v1/reconciliation/transactions' => Http::response(['transactionsInfo' => $this->reconciliationRows]),
                default => Http::response(['errorCode' => 'NOT_FAKED'], 599),
            };
        });

        $this->seed([AccessControlSeeder::class]);
        $this->user = User::query()->create([
            'name' => 'Buyer', 'email' => 'buyer-mia@example.com', 'password' => 'password',
            'preferred_locale' => 'ro', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->user->roles()->sync([Role::query()->where('name', 'Viewer')->firstOrFail()->id]);
        app(WalletService::class)->ensureWallet($this->user);
        app(AccountProfileService::class)->ensureDefaultProfile($this->user);
        [, $this->token] = PersonalAccessToken::issue($this->user, 'client-test');
        $this->startingBalance = $this->balance();
    }

    private function balance(): float
    {
        return (float) $this->user->wallet()->first()->balance_amount;
    }

    public function test_methods_endpoint_offers_mia_when_configured(): void
    {
        $this->getJson('/api/v1/storefront/wallet/top-up-methods', $this->auth())
            ->assertOk()
            ->assertJsonFragment(['id' => 'mia', 'enabled' => true]);

        config(['services.mia.enabled' => false]);

        $this->getJson('/api/v1/storefront/wallet/top-up-methods', $this->auth())
            ->assertOk()
            ->assertJsonFragment(['id' => 'mia', 'enabled' => false]);
    }

    public function test_creating_a_mia_top_up_returns_a_dynamic_qr_without_asking_for_a_phone(): void
    {
        $response = $this->createTopUp(150)
            ->assertCreated()
            ->assertJsonPath('top_up.provider', 'mia')
            ->assertJsonPath('top_up.status', 'processing')
            ->assertJsonPath('top_up.qr.text', 'https://mia-qr-test.bnm.md/1/e/BNM/VIChdr1')
            ->assertJsonPath('top_up.qr.image', 'data:image/png;base64,'.base64_encode('png-bytes'));

        $topUp = PaymentTopUp::query()->where('uuid', $response->json('top_up.id'))->sole();
        $this->assertSame('ext-1', $topUp->qr_extension_uuid);
        $this->assertMatchesRegularExpression('/^FLM'.str_pad((string) $topUp->id, 9, '0', STR_PAD_LEFT).'[A-Z0-9]{4}$/', $topUp->provider_reference);

        Http::assertSent(function (HttpRequest $request) use ($topUp): bool {
            if (parse_url($request->url(), PHP_URL_PATH) !== '/api/v1/qr') {
                return false;
            }

            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer access-token')
                && $body['header'] === ['qrType' => 'DYNM', 'amountType' => 'Fixed', 'pmtContext' => 'e']
                && $body['extension']['amount'] === ['sum' => '150.00', 'currency' => 'MDL']
                && $body['extension']['creditorRef'] === $topUp->provider_reference
                && $body['extension']['ttl'] === ['length' => 15, 'units' => 'mm'];
        });
    }

    public function test_a_signed_payment_signal_credits_the_wallet_exactly_once(): void
    {
        $topUp = $this->createdTopUp(150);
        $signal = $this->signal(['signalCode' => 'Payment', 'qrExtensionUUID' => 'ext-1', 'payment' => $this->payment('150.00')]);

        $this->postSignal($signal)->assertNoContent();
        $this->postSignal($signal)->assertNoContent();

        $topUp->refresh();
        $this->assertSame(PaymentTopUp::STATUS_PAID, $topUp->status);
        $this->assertSame(self::TRANSACTION_ID, $topUp->provider_rrn);
        $this->assertSame(self::REFERENCE, $topUp->provider_order_id);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->count());
        $this->assertEqualsWithDelta($this->startingBalance + 150.0, $this->balance(), 0.001);
    }

    public function test_a_signal_signed_with_another_key_is_rejected(): void
    {
        $this->createdTopUp(150);
        $forger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $signal = $this->signal(['signalCode' => 'Payment', 'qrExtensionUUID' => 'ext-1', 'payment' => $this->payment('150.00')], $forger);

        $this->postSignal($signal)->assertStatus(400);
        $this->postSignal($this->unsignedSignal(['signalCode' => 'Payment', 'qrExtensionUUID' => 'ext-1']))->assertStatus(400);

        $this->assertSame(0, WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->count());
    }

    public function test_a_signal_for_an_unknown_qr_is_not_found(): void
    {
        $this->postSignal($this->signal(['signalCode' => 'Payment', 'qrExtensionUUID' => 'nope', 'payment' => $this->payment('1.00')]))
            ->assertNotFound();
    }

    public function test_a_payment_for_a_different_amount_is_not_credited(): void
    {
        $topUp = $this->createdTopUp(150);

        $this->postSignal($this->signal(['signalCode' => 'Payment', 'qrExtensionUUID' => 'ext-1', 'payment' => $this->payment('15.00')]))
            ->assertNoContent();

        $this->assertSame('amount_mismatch', $topUp->fresh()->provider_status);
        $this->assertNull($topUp->fresh()->credited_at);
    }

    public function test_an_expiration_signal_closes_the_top_up(): void
    {
        $topUp = $this->createdTopUp(150);

        $this->postSignal($this->signal(['signalCode' => 'Expiration', 'qrExtensionUUID' => 'ext-1']))->assertNoContent();

        $this->assertSame(PaymentTopUp::STATUS_CANCELED, $topUp->fresh()->status);
    }

    public function test_the_poller_settles_a_paid_qr_when_no_signal_arrived(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->statusResponse = ['uuid' => 'ext-1', 'status' => 'Paid', 'payments' => [[
            'system' => 'IPS', 'reference' => self::REFERENCE, 'amount' => ['sum' => 150, 'currency' => 'MDL'],
        ]]];

        $this->artisan('payments:poll-topups')->assertSuccessful();

        $this->assertSame(PaymentTopUp::STATUS_PAID, $topUp->fresh()->status);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->count());
    }

    public function test_the_poller_closes_an_expired_qr(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->statusResponse = ['uuid' => 'ext-1', 'status' => 'Expired', 'payments' => []];

        $this->artisan('payments:poll-topups')->assertSuccessful();

        $this->assertSame(PaymentTopUp::STATUS_CANCELED, $topUp->fresh()->status);
        $this->assertSame('Expired', $topUp->fresh()->provider_status);
    }

    public function test_the_customer_can_cancel_an_open_qr(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->statusResponse = ['uuid' => 'ext-1', 'status' => 'Cancelled', 'payments' => []];

        $this->postJson('/api/v1/storefront/wallet/top-ups/'.$topUp->uuid.'/cancel', [], $this->auth())
            ->assertOk()
            ->assertJsonPath('top_up.status', 'canceled')
            ->assertJsonPath('top_up.qr', null);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/qr/hdr-1');
    }

    public function test_a_partial_refund_debits_the_wallet_and_blocks_any_further_refund(): void
    {
        $topUp = $this->paidTopUp(150);
        $mia = app(MiaPaymentService::class);

        $refund = $mia->refundTopUp($topUp, 50, 'Client request');

        $this->assertSame(PaymentRefund::STATUS_SUCCEEDED, $refund->status);
        $this->assertEqualsWithDelta($this->startingBalance + 100.0, $this->balance(), 0.001);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/transaction/'.self::TRANSACTION_ID.'/partial-refund'
            && (float) ($request->data()['amount'] ?? 0) === 50.0);

        $this->expectException(ValidationException::class);
        $mia->refundTopUp($topUp->fresh(), 10, 'Again');
    }

    public function test_a_full_refund_uses_the_full_refund_endpoint(): void
    {
        $topUp = $this->paidTopUp(150);

        app(MiaPaymentService::class)->refundTopUp($topUp, 150, 'Client request');

        $this->assertSame(PaymentTopUp::STATUS_REFUNDED, $topUp->fresh()->status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/transaction/'.self::TRANSACTION_ID);
    }

    public function test_a_refund_with_an_unknown_outcome_is_settled_from_reconciliation(): void
    {
        $topUp = $this->paidTopUp(150);
        $this->refundHttpStatus = 500;

        $refund = app(MiaPaymentService::class)->refundTopUp($topUp, 150, 'Client request');

        $this->assertSame(PaymentRefund::STATUS_REQUESTED, $refund->status);
        $this->assertEqualsWithDelta($this->startingBalance + 150.0, $this->balance(), 0.001);

        $this->reconciliationRows = [[
            'id' => 'VICBMD2XAXXX261118000000000000002',
            'transactionType' => 'QRREFUND',
            'transactionAmount' => 150,
            'transactionStatus' => 'Approved',
            'transactionMessage' => 'Return for '.self::TRANSACTION_ID.' 11/18/2026',
        ]];
        $refund->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $this->artisan('payments:poll-topups')->assertSuccessful();

        $this->assertSame(PaymentRefund::STATUS_SUCCEEDED, $refund->fresh()->status);
        $this->assertEqualsWithDelta($this->startingBalance, $this->balance(), 0.001);
    }

    public function test_card_top_ups_are_refused_when_the_card_channel_is_switched_off(): void
    {
        config(['services.payments.card_enabled' => false]);

        $this->postJson('/api/v1/storefront/wallet/top-ups', [
            'method' => 'card',
            'amount' => 150,
            'phone' => '069484967',
            'billing_address' => $this->billingAddress(),
        ], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');
    }

    private function createTopUp(float $amount): TestResponse
    {
        return $this->postJson('/api/v1/storefront/wallet/top-ups', [
            'method' => 'mia',
            'amount' => $amount,
            'billing_address' => $this->billingAddress(),
        ], $this->auth());
    }

    private function createdTopUp(float $amount): PaymentTopUp
    {
        $uuid = $this->createTopUp($amount)->assertCreated()->json('top_up.id');

        return PaymentTopUp::query()->where('uuid', $uuid)->sole();
    }

    private function paidTopUp(float $amount): PaymentTopUp
    {
        $topUp = $this->createdTopUp($amount);
        $this->postSignal($this->signal([
            'signalCode' => 'Payment',
            'qrExtensionUUID' => 'ext-1',
            'payment' => $this->payment(number_format($amount, 2, '.', '')),
        ]))->assertNoContent();

        return $topUp->fresh();
    }

    private function payment(string $sum): array
    {
        return ['system' => 'IPS', 'reference' => self::REFERENCE, 'amount' => ['sum' => $sum, 'currency' => 'MDL']];
    }

    private function signal(array $payload, $key = null): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $body = $this->base64Url(json_encode(['signalDtTm' => now()->toIso8601String(), ...$payload]));
        openssl_sign($header.'.'.$body, $signature, $key ?? $this->bankKey, OPENSSL_ALGO_SHA256);

        return $header.'.'.$body.'.'.$this->base64Url($signature);
    }

    private function unsignedSignal(array $payload): string
    {
        return $this->base64Url(json_encode(['alg' => 'none'])).'.'.$this->base64Url(json_encode($payload)).'.';
    }

    private function postSignal(string $jwt): TestResponse
    {
        // The bank posts the JWT as a JSON string body.
        return $this->call('POST', '/api/signals', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($jwt));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function billingAddress(): array
    {
        return [
            'full_name' => 'Buyer', 'country_code' => 'MD', 'city' => 'Chișinău',
            'postal_code' => 'MD-2001', 'address_line1' => 'Strada Test 10',
        ];
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }
}
