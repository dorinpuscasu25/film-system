<?php

namespace Tests\Feature\Api;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AccountProfileService;
use App\Services\Payments\CyberSource\CyberSourcePaymentService;
use App\Services\Payments\CyberSource\CyberSourceSdkFactory;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Victoriabank\CyberSource\Exception\UnknownStatusException;
use Victoriabank\CyberSource\Jwt;
use Victoriabank\CyberSource\Transport;

/**
 * Card top-ups through Victoriabank CyberSource Unified Checkout (scenario A). Only the network
 * is faked: request building, rule checks, JWT verification and 3DS decisions are the bank SDK's.
 */
class CyberSourceTopUpTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://apitest.cybersource.com';

    private const KID = 'test-kid-1';

    private const ORIGIN = 'https://filmoteca.test';

    private const PAYMENT_ID = '7690095163406343404806';

    protected User $user;

    protected string $token;

    protected FakeCyberSourceTransport $transport;

    /** @var \OpenSSLAsymmetricKey */
    protected $cyberSourceKey;

    protected float $startingBalance = 0.0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payments.card_enabled' => true,
            'services.payments.card_provider' => 'cybersource',
            'services.cybersource' => [
                'enabled' => true,
                'environment' => 'test',
                'base_url' => null,
                'merchant_id' => 'film_test001',
                'key_id' => 'key-id',
                'shared_secret' => base64_encode(random_bytes(32)),
                'target_origins' => [self::ORIGIN, 'https://www.filmoteca.test'],
                'capture' => true,
                'payment_types' => ['PANENTRY'],
                'accept_attempted_3ds' => null,
                'webhook_key_id' => 'wh-key-id',
                'webhook_key' => base64_encode(str_repeat('k', 32)),
                'webhook_mle_private_key_path' => null,
                'webhook_mle_private_key_passphrase' => null,
                'timeout' => 65,
            ],
        ]);

        $this->cyberSourceKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($this->cyberSourceKey)['rsa'];
        // CyberSource's public key for RS256 verification, pinned instead of fetched from /flex/v2/public-keys.
        Jwt::trustKey(self::BASE, self::KID, ['kty' => 'RSA', 'n' => $this->b64url($rsa['n']), 'e' => $this->b64url($rsa['e'])]);

        $this->transport = new FakeCyberSourceTransport(self::BASE, $this->signedJwt([
            'exp' => time() + 900,
            'ctx' => [['data' => [
                'clientLibrary' => 'https://apitest.cybersource.com/up/v1/assets/0.20.0/SecureAcceptance.js',
                'clientLibraryIntegrity' => 'sha256-abc',
                'targetOrigins' => [self::ORIGIN],
            ]]],
        ]));
        app(CyberSourceSdkFactory::class)->useTransport($this->transport);

        $this->seed([AccessControlSeeder::class]);
        $this->user = User::query()->create([
            'name' => 'Ion Popescu', 'email' => 'buyer-card@example.com', 'password' => 'password',
            'preferred_locale' => 'ro', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->user->roles()->sync([Role::query()->where('name', 'Viewer')->firstOrFail()->id]);
        app(WalletService::class)->ensureWallet($this->user);
        app(AccountProfileService::class)->ensureDefaultProfile($this->user);
        [, $this->token] = PersonalAccessToken::issue($this->user, 'client-test');
        $this->startingBalance = $this->balance();
    }

    public function test_card_is_offered_through_cybersource_without_a_phone(): void
    {
        $this->getJson('/api/v1/storefront/wallet/top-up-methods', $this->auth())
            ->assertOk()
            ->assertJsonFragment(['id' => 'card', 'enabled' => true, 'provider' => 'cybersource', 'requires_phone' => false]);
    }

    public function test_creating_a_card_top_up_returns_an_embedded_unified_checkout_session(): void
    {
        $response = $this->createTopUp(150, ['Origin' => 'https://www.filmoteca.test'])
            ->assertCreated()
            ->assertJsonPath('top_up.provider', 'cybersource')
            ->assertJsonPath('top_up.status', 'processing')
            ->assertJsonPath('top_up.card_session.client_library', 'https://apitest.cybersource.com/up/v1/assets/0.20.0/SecureAcceptance.js')
            ->assertJsonPath('top_up.card_session.client_library_integrity', 'sha256-abc');

        $this->assertNotEmpty($response->json('top_up.card_session.session_jwt'));

        $session = $this->transport->lastRequest('POST', '/uc/v1/sessions');
        $topUp = PaymentTopUp::query()->sole();
        $this->assertSame(['https://www.filmoteca.test'], $session['targetOrigins']);
        $this->assertSame(['type' => 'CAPTURE', 'decisionManager' => false, 'consumerAuthentication' => '3DS'], $session['completeMandate']);
        $this->assertSame(['PANENTRY'], $session['allowedPaymentTypes']);
        $this->assertSame($topUp->provider_reference, $session['data']['clientReferenceInformation']['code']);
        $this->assertSame(['totalAmount' => '150.00', 'currency' => 'MDL'], $session['data']['orderInformation']['amountDetails']);
        $this->assertSame('Ion', $session['data']['orderInformation']['billTo']['firstName']);
        $this->assertSame('Chisinau', $session['data']['orderInformation']['billTo']['locality']);
    }

    public function test_an_origin_that_is_not_configured_never_reaches_the_session(): void
    {
        $this->createTopUp(150, ['Origin' => 'https://evil.example'])->assertCreated();

        $this->assertSame([self::ORIGIN], $this->transport->lastRequest('POST', '/uc/v1/sessions')['targetOrigins']);
    }

    public function test_a_confirmed_payment_with_full_3ds_credits_the_wallet_once(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '05');

        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'paid');
        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'paid');

        $topUp->refresh();
        $this->assertSame(self::PAYMENT_ID, $topUp->provider_checkout_id);
        $this->assertSame('602115262637', $topUp->provider_rrn);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->count());
        $this->assertEqualsWithDelta($this->startingBalance + 150, $this->balance(), 0.001);
        $this->assertNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/voids'));
    }

    public function test_auth_mode_captures_before_crediting_and_refunds_the_capture(): void
    {
        config(['services.cybersource.capture' => false]);
        $topUp = $this->createdTopUp(150);
        $this->assertSame('AUTH', $this->transport->lastRequest('POST', '/uc/v1/sessions')['completeMandate']['type']);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '05');

        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'paid');

        $capture = $this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/captures');
        $this->assertSame($topUp->provider_reference.'-CP1', $capture['clientReferenceInformation']['code']);
        $this->assertSame('capture-1', $topUp->fresh()->raw_details['capture_id']);

        app(CyberSourcePaymentService::class)->refundTopUp($topUp->fresh(), 150, 'Client request');
        $this->assertNotNull($this->transport->lastRequest('POST', '/pts/v2/payments/capture-1/refunds'));
    }

    public function test_auth_mode_reverses_a_payment_without_full_3ds(): void
    {
        config(['services.cybersource.capture' => false]);
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '07', cavv: null);

        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'failed');

        $reversal = $this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/reversals');
        $this->assertSame('150.00', $reversal['reversalInformation']['amountDetails']['totalAmount']);
        $this->assertNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/captures'));
    }

    public function test_a_real_sandbox_answer_is_understood(): void
    {
        // Shape of GET /tss/v2/transactions/{id} on apitest.cybersource.com (2026-11-19): no
        // applicationInformation.status, ECI "5", amount "200"; the UC result JWT is not "AUTHORIZED".
        config(['services.cybersource.capture' => false]);
        $topUp = $this->createdTopUp(200);
        $this->transport->transactions[self::PAYMENT_ID] = $this->realTransaction($topUp, authRCode: '1', eci: '5', cavv: 'AJkBBkhgQQAAAE4gSEJydQAAAAA=');

        $this->submitResult($topUp, status: 'COMPLETED')->assertOk()->assertJsonPath('top_up.status', 'paid');

        $this->assertSame('05', $topUp->fresh()->raw_details['eci']);
        $this->assertNotNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/captures'));
        $this->assertNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/reversals'));
    }

    public function test_a_real_sandbox_decline_fails_without_release(): void
    {
        config(['services.cybersource.capture' => false]);
        $topUp = $this->createdTopUp(200);
        $this->transport->transactions[self::PAYMENT_ID] = $this->realTransaction($topUp, authRCode: '0', eci: '5', cavv: 'AJkBBkhgQQAAAE4gSEJydQAAAAA=');

        $this->submitResult($topUp, status: 'COMPLETED')->assertOk()->assertJsonPath('top_up.status', 'failed');

        $this->assertNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/reversals'));
    }

    public function test_a_real_sandbox_answer_without_3ds_is_reversed(): void
    {
        config(['services.cybersource.capture' => false]);
        $topUp = $this->createdTopUp(200);
        $this->transport->transactions[self::PAYMENT_ID] = $this->realTransaction($topUp, authRCode: '1', eci: '7', cavv: null);

        $this->submitResult($topUp, status: 'COMPLETED')->assertOk()->assertJsonPath('top_up.status', 'failed');

        $this->assertNotNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/reversals'));
    }

    public function test_a_payment_without_full_3ds_is_voided_not_credited(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '07', cavv: null);

        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'failed');

        $this->assertSame('released_without_full_3ds', $topUp->fresh()->provider_status);
        $this->assertSame(0, WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->count());
        $void = $this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/voids');
        $this->assertSame($topUp->provider_reference.'-VD1', $void['clientReferenceInformation']['code']);
    }

    public function test_a_declined_payment_fails_the_top_up(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', status: 'DECLINED', eci: null, cavv: null);

        $this->submitResult($topUp, status: 'DECLINED')->assertOk()->assertJsonPath('top_up.status', 'failed');

        $this->assertNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/voids'));
    }

    public function test_a_result_belonging_to_another_order_is_released(): void
    {
        $topUp = $this->createdTopUp(150);
        $tx = $this->transaction($topUp, '150.00', eci: '05');
        $tx['clientReferenceInformation']['code'] = 'FLM999999999';
        $this->transport->transactions[self::PAYMENT_ID] = $tx;

        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'processing');

        $this->assertSame('result_mismatch', $topUp->fresh()->provider_status);
        $this->assertNull($topUp->fresh()->credited_at);
        $this->assertNotNull($this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/voids'));
    }

    public function test_a_forged_result_is_rejected(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '05');
        $forger = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $this->postJson('/api/v1/storefront/wallet/top-ups/'.$topUp->uuid.'/card-result', [
            'result_jwt' => $this->signedJwt(['id' => self::PAYMENT_ID, 'status' => 'AUTHORIZED'], $forger),
        ], $this->auth())->assertUnprocessable()->assertJsonValidationErrors('result_jwt');

        $this->assertNull($topUp->fresh()->credited_at);
    }

    public function test_the_poller_finds_a_payment_whose_result_never_came_back(): void
    {
        $topUp = $this->createdTopUp(150);
        $topUp->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $this->transport->searchResults[$topUp->provider_reference] = [[
            'id' => self::PAYMENT_ID,
            'clientReferenceInformation' => ['code' => $topUp->provider_reference],
            'applicationInformation' => ['applications' => [['name' => 'ics_auth', 'rCode' => '1']]],
        ]];
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '02', type: '002');

        $this->artisan('payments:poll-topups')->assertSuccessful();

        $this->assertSame(PaymentTopUp::STATUS_PAID, $topUp->fresh()->status);
        $this->assertEqualsWithDelta($this->startingBalance + 150, $this->balance(), 0.001);
    }

    public function test_the_poller_abandons_a_session_nobody_paid(): void
    {
        $topUp = $this->createdTopUp(150);
        $topUp->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('payments:poll-topups')->assertSuccessful();

        $this->assertSame(PaymentTopUp::STATUS_CANCELED, $topUp->fresh()->status);
        $this->assertSame('session_expired', $topUp->fresh()->provider_status);
    }

    public function test_a_partial_refund_is_sent_on_the_sale_and_debits_the_wallet(): void
    {
        $topUp = $this->paidTopUp(150);

        $refund = app(CyberSourcePaymentService::class)->refundTopUp($topUp, 40, 'Client request');

        $this->assertSame(PaymentRefund::STATUS_SUCCEEDED, $refund->status);
        $this->assertEqualsWithDelta($this->startingBalance + 110, $this->balance(), 0.001);
        $body = $this->transport->lastRequest('POST', '/pts/v2/payments/'.self::PAYMENT_ID.'/refunds');
        $this->assertSame($topUp->provider_reference.'-RF1', $body['clientReferenceInformation']['code']);
        $this->assertSame('40.00', $body['orderInformation']['amountDetails']['totalAmount']);

        // Card refunds may be split (unlike MIA).
        app(CyberSourcePaymentService::class)->refundTopUp($topUp->fresh(), 110, 'Rest');
        $this->assertSame(PaymentTopUp::STATUS_REFUNDED, $topUp->fresh()->status);
    }

    public function test_a_refund_with_an_unknown_outcome_is_reconciled_by_reference(): void
    {
        $topUp = $this->paidTopUp(150);
        $this->transport->failNext['POST /pts/v2/payments/'.self::PAYMENT_ID.'/refunds'] = true;

        $refund = app(CyberSourcePaymentService::class)->refundTopUp($topUp, 150, 'Client request');
        $this->assertSame(PaymentRefund::STATUS_REQUESTED, $refund->status);

        $this->transport->searchResults[$topUp->provider_reference.'-RF1'] = [[
            'id' => '7690000000000000000099',
            'clientReferenceInformation' => ['code' => $topUp->provider_reference.'-RF1'],
            'applicationInformation' => ['applications' => [['name' => 'ics_credit', 'rCode' => '1']]],
        ]];
        $refund->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $this->artisan('payments:poll-topups')->assertSuccessful();

        $this->assertSame(PaymentRefund::STATUS_SUCCEEDED, $refund->fresh()->status);
        $this->assertEqualsWithDelta($this->startingBalance, $this->balance(), 0.001);
    }

    public function test_a_signed_webhook_settles_the_payment_through_the_api(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '05');
        $body = json_encode([
            'eventType' => 'uc.orders.transactionresults',
            'webhookId' => 'wh-1',
            'payload' => ['id' => self::PAYMENT_ID, 'status' => 'AUTHORIZED'],
        ]);

        $this->postWebhook($body, $this->webhookSignature($body))->assertOk();
        $this->postWebhook($body, $this->webhookSignature($body))->assertOk();

        $this->assertSame(PaymentTopUp::STATUS_PAID, $topUp->fresh()->status);
        $this->assertSame(1, WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->count());
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected(): void
    {
        $topUp = $this->createdTopUp(150);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, '150.00', eci: '05');
        $body = json_encode(['eventType' => 'uc.orders.transactionresults', 'payload' => ['id' => self::PAYMENT_ID]]);

        $this->postWebhook($body, 't=1;keyId=wh-key-id;sig='.base64_encode(str_repeat('x', 32)))->assertUnauthorized();

        $this->assertNull($topUp->fresh()->credited_at);
    }

    private function createTopUp(float $amount, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/storefront/wallet/top-ups', [
            'method' => 'card',
            'amount' => $amount,
            'billing_address' => [
                'full_name' => 'Ion Popescu', 'country_code' => 'MD', 'city' => 'Chișinău',
                'postal_code' => 'MD-2004', 'address_line1' => 'str. 31 August 1989, nr. 141',
            ],
        ], [...$this->auth(), ...$headers]);
    }

    private function createdTopUp(float $amount): PaymentTopUp
    {
        $uuid = $this->createTopUp($amount)->assertCreated()->json('top_up.id');

        return PaymentTopUp::query()->where('uuid', $uuid)->sole();
    }

    private function paidTopUp(float $amount): PaymentTopUp
    {
        $topUp = $this->createdTopUp($amount);
        $this->transport->transactions[self::PAYMENT_ID] = $this->transaction($topUp, number_format($amount, 2, '.', ''), eci: '05');
        $this->submitResult($topUp)->assertOk()->assertJsonPath('top_up.status', 'paid');

        return $topUp->fresh();
    }

    private function submitResult(PaymentTopUp $topUp, string $status = 'AUTHORIZED'): TestResponse
    {
        return $this->postJson('/api/v1/storefront/wallet/top-ups/'.$topUp->uuid.'/card-result', [
            'result_jwt' => $this->signedJwt(['id' => self::PAYMENT_ID, 'status' => $status, 'outcome' => $status]),
        ], $this->auth());
    }

    private function transaction(PaymentTopUp $topUp, string $amount, string $status = 'AUTHORIZED', ?string $eci = '05', ?string $cavv = 'AAABBEg0VhI0VniQEjRWAAAAAAA=', string $type = '001'): array
    {
        return [
            'id' => self::PAYMENT_ID,
            'clientReferenceInformation' => ['code' => $topUp->provider_reference],
            'applicationInformation' => ['status' => $status],
            'orderInformation' => ['amountDetails' => ['totalAmount' => $amount, 'authorizedAmount' => $amount, 'currency' => 'MDL']],
            'consumerAuthenticationInformation' => array_filter(['eciRaw' => $eci, 'cavv' => $cavv]),
            'paymentAccountInformation' => ['card' => ['type' => $type]],
            'processorInformation' => ['retrievalReferenceNumber' => '602115262637', 'networkTransactionId' => '016153570198200'],
        ];
    }

    private function realTransaction(PaymentTopUp $topUp, string $authRCode, string $eci, ?string $cavv): array
    {
        return [
            'id' => self::PAYMENT_ID,
            'applicationInformation' => [
                'reasonCode' => $authRCode === '1' ? 100 : 203,
                'applications' => [
                    ['name' => 'ics_auth', 'reasonCode' => $authRCode === '1' ? '100' : '203', 'rCode' => $authRCode, 'rFlag' => $authRCode === '1' ? 'SOK' : 'DCARDREFUSED'],
                    ['name' => 'ics_pa_enroll', 'reasonCode' => '100', 'rCode' => '1', 'rFlag' => 'SOK'],
                ],
            ],
            'clientReferenceInformation' => ['code' => $topUp->provider_reference, 'applicationName' => 'unifiedCheckout'],
            'consumerAuthenticationInformation' => array_filter(['eciRaw' => $eci, 'cavv' => $cavv, 'paSpecificationVersion' => '2.2.0']),
            'orderInformation' => ['amountDetails' => ['totalAmount' => '200', 'currency' => 'MDL', 'authorizedAmount' => '200']],
            'paymentInformation' => ['card' => ['type' => '001', 'suffix' => '2701']],
            'processingInformation' => ['commerceIndicator' => '5', 'commerceIndicatorLabel' => 'vbv'],
            'processorInformation' => ['networkTransactionId' => '016153570198200', 'retrievalReferenceNumber' => '632312658331', 'responseCode' => '00'],
        ];
    }

    private function signedJwt(array $payload, $key = null): string
    {
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT']));
        $body = $this->b64url(json_encode($payload));
        openssl_sign($header.'.'.$body, $signature, $key ?? $this->cyberSourceKey, OPENSSL_ALGO_SHA256);

        return $header.'.'.$body.'.'.$this->b64url($signature);
    }

    private function webhookSignature(string $body): string
    {
        $t = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $t.'.'.$body, str_repeat('k', 32), true));

        return "t={$t};keyId=wh-key-id;sig={$sig}";
    }

    private function postWebhook(string $body, string $signature): TestResponse
    {
        return $this->call('POST', '/api/v1/webhooks/cybersource', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_V_C_SIGNATURE' => $signature,
        ], $body);
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function balance(): float
    {
        return (float) $this->user->wallet()->first()->balance_amount;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }
}

/** Stands in for apitest.cybersource.com; records every request it receives. */
class FakeCyberSourceTransport implements Transport
{
    /** @var array<int, array{0: string, 1: string, 2: ?array}> */
    public array $requests = [];

    /** @var array<string, array> */
    public array $transactions = [];

    /** @var array<string, array<int, array>> */
    public array $searchResults = [];

    /** @var array<string, bool> "METHOD path" => throw UnknownStatusException once */
    public array $failNext = [];

    public function __construct(private string $base, private string $sessionJwt) {}

    public function baseUrl(): string
    {
        return $this->base;
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $this->requests[] = [$method, $path, $body];

        if ($this->failNext[$method.' '.$path] ?? false) {
            unset($this->failNext[$method.' '.$path]);

            throw new UnknownStatusException("timeout $method $path");
        }

        if ($method === 'POST' && $path === '/uc/v1/sessions') {
            return [201, $this->sessionJwt];
        }

        if ($method === 'GET' && str_starts_with($path, '/tss/v2/transactions/')) {
            $id = substr($path, strlen('/tss/v2/transactions/'));

            return isset($this->transactions[$id]) ? [200, $this->transactions[$id]] : [404, ['reason' => 'NOT_FOUND']];
        }

        if ($method === 'POST' && $path === '/tss/v2/searches') {
            preg_match('/clientReferenceInformation\.code:([A-Za-z0-9-]+)/', (string) ($body['query'] ?? ''), $match);
            $found = $this->searchResults[$match[1] ?? ''] ?? [];

            return [201, ['count' => count($found), '_embedded' => ['transactionSummaries' => $found]]];
        }

        if ($method === 'POST' && str_ends_with($path, '/voids')) {
            return [201, ['id' => 'void-1', 'status' => 'VOIDED']];
        }

        if ($method === 'POST' && str_ends_with($path, '/reversals')) {
            return [201, ['id' => 'rev-1', 'status' => 'REVERSED']];
        }

        if ($method === 'POST' && str_ends_with($path, '/refunds')) {
            return [201, ['id' => 'refund-'.count($this->requests), 'status' => 'PENDING']];
        }

        if ($method === 'POST' && str_ends_with($path, '/captures')) {
            return [201, ['id' => 'capture-1', 'status' => 'PENDING']];
        }

        return [404, ['reason' => 'NOT_FAKED']];
    }

    public function lastRequest(string $method, string $path): ?array
    {
        foreach (array_reverse($this->requests) as [$m, $p, $b]) {
            if ($m === $method && $p === $path) {
                return $b ?? [];
            }
        }

        return null;
    }
}
