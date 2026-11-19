<?php

namespace Tests\Feature\Api;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\Payments\CyberSource\CyberSourcePaymentService;
use App\Services\Payments\CyberSource\CyberSourceSdkFactory;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * End-to-end against the Victoriabank CyberSource simulator (real JWT v2 auth, real rule checks,
 * real RS256 keys). Opt-in: start the simulator, then
 *
 *   CYBERSOURCE_SIMULATOR_URL=http://127.0.0.1:8770 CYBERSOURCE_SIMULATOR_KEY_ID=… \
 *   CYBERSOURCE_SIMULATOR_SECRET=… CYBERSOURCE_SIMULATOR_SITE=http://127.0.0.1:5174 \
 *   php artisan test --filter=CyberSourceSimulatorTest
 *
 * (simulator: node simulator/vb-cybs-simulator.js --site <SITE> --key-id <KEY_ID> --secret <SECRET>)
 */
class CyberSourceSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected string $simulator;

    protected string $site;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulator = rtrim((string) getenv('CYBERSOURCE_SIMULATOR_URL'), '/');
        if ($this->simulator === '') {
            $this->markTestSkipped('Set CYBERSOURCE_SIMULATOR_URL to run against the Victoriabank simulator.');
        }

        $this->site = rtrim((string) (getenv('CYBERSOURCE_SIMULATOR_SITE') ?: 'http://127.0.0.1:5174'), '/');

        config([
            'services.payments.card_enabled' => true,
            'services.payments.card_provider' => 'cybersource',
            'services.cybersource.enabled' => true,
            'services.cybersource.environment' => 'test',
            'services.cybersource.base_url' => $this->simulator,
            'services.cybersource.merchant_id' => getenv('CYBERSOURCE_SIMULATOR_MID') ?: 'vb_sim_merchant',
            'services.cybersource.key_id' => (string) getenv('CYBERSOURCE_SIMULATOR_KEY_ID'),
            'services.cybersource.shared_secret' => (string) getenv('CYBERSOURCE_SIMULATOR_SECRET'),
            'services.cybersource.target_origins' => [$this->site],
            'services.cybersource.capture' => filter_var(getenv('CYBERSOURCE_SIMULATOR_CAPTURE') ?: 'false', FILTER_VALIDATE_BOOL),
            'services.cybersource.payment_types' => ['PANENTRY'],
        ]);
        app(CyberSourceSdkFactory::class)->useTransport(null);

        $this->seed([AccessControlSeeder::class]);
        $this->user = User::query()->create([
            'name' => 'Ion Popescu', 'email' => 'sim-'.uniqid().'@example.com', 'password' => 'password',
            'preferred_locale' => 'ro', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->user->roles()->sync([Role::query()->where('name', 'Viewer')->firstOrFail()->id]);
        app(WalletService::class)->ensureWallet($this->user);
        app(AccountProfileService::class)->ensureDefaultProfile($this->user);
        [, $this->token] = PersonalAccessToken::issue($this->user, 'client-test');
    }

    public function test_frictionless_3ds_payment_is_credited_and_partially_refunded(): void
    {
        [$topUp, $session] = $this->startTopUp(150);

        $result = $this->complete($session, '4000000000002701');
        $this->submit($topUp, $result)->assertOk()->assertJsonPath('top_up.status', 'paid');

        $refund = app(CyberSourcePaymentService::class)->refundTopUp($topUp->fresh(), 50, 'Simulator refund');
        $this->assertSame(PaymentRefund::STATUS_SUCCEEDED, $refund->status);
    }

    public function test_full_refund_returns_the_whole_payment(): void
    {
        [$topUp, $session] = $this->startTopUp(90);

        $this->submit($topUp, $this->complete($session, '4000000000002701'))->assertOk()->assertJsonPath('top_up.status', 'paid');

        app(CyberSourcePaymentService::class)->refundTopUp($topUp->fresh(), 90, 'Simulator full refund');
        $this->assertSame(PaymentTopUp::STATUS_REFUNDED, $topUp->fresh()->status);
    }

    public function test_challenge_3ds_payment_is_credited_after_the_otp(): void
    {
        [$topUp, $session] = $this->startTopUp(75.5);

        $result = $this->complete($session, '4000000000002503', otp: '1234');
        $this->submit($topUp, $result)->assertOk()->assertJsonPath('top_up.status', 'paid');
    }

    public function test_mastercard_frictionless_payment_is_credited(): void
    {
        [$topUp, $session] = $this->startTopUp(200);

        $result = $this->complete($session, '5200000000002235');
        $this->submit($topUp, $result)->assertOk()->assertJsonPath('top_up.status', 'paid');
    }

    public function test_payment_without_3ds_evidence_is_voided(): void
    {
        [$topUp, $session] = $this->startTopUp(120);

        $result = $this->complete($session, '4000000000002313');
        $this->submit($topUp, $result)->assertOk()->assertJsonPath('top_up.status', 'failed');

        $this->assertSame('released_without_full_3ds', $topUp->fresh()->provider_status);
    }

    /** @return array{0: PaymentTopUp, 1: string} */
    private function startTopUp(float $amount): array
    {
        $response = $this->postJson('/api/v1/storefront/wallet/top-ups', [
            'method' => 'card',
            'amount' => $amount,
            'billing_address' => [
                'full_name' => 'Ion Popescu', 'country_code' => 'MD', 'city' => 'Chișinău',
                'postal_code' => 'MD-2004', 'address_line1' => 'str. 31 August 1989, nr. 141',
            ],
        ], ['Authorization' => 'Bearer '.$this->token, 'Origin' => $this->site])->assertCreated();

        return [
            PaymentTopUp::query()->where('uuid', $response->json('top_up.id'))->sole(),
            (string) $response->json('top_up.card_session.session_jwt'),
        ];
    }

    /** What the browser library does: the customer types a test card in the simulated checkout. */
    private function complete(string $session, string $number, ?string $otp = null): string
    {
        $body = [
            'session' => $session,
            'card' => ['number' => $number, 'expMonth' => '01', 'expYear' => '2029', 'cvv' => '123'],
            'autoProcessing' => true,
        ];

        $first = Http::post($this->simulator.'/sim/uc/complete', $body)->throw()->json();
        if (! empty($first['challenge'])) {
            $this->assertNotNull($otp, 'The card asked for a 3DS challenge');
            $first = Http::post($this->simulator.'/sim/uc/complete', [...$body, 'otp' => $otp])->throw()->json();
        }

        $this->assertIsString($first['result'] ?? null);

        return $first['result'];
    }

    private function submit(PaymentTopUp $topUp, string $resultJwt): TestResponse
    {
        return $this->postJson('/api/v1/storefront/wallet/top-ups/'.$topUp->uuid.'/card-result', [
            'result_jwt' => $resultJwt,
        ], ['Authorization' => 'Bearer '.$this->token]);
    }
}
