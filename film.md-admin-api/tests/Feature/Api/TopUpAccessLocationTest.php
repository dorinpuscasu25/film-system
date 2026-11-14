<?php

namespace Tests\Feature\Api;

use App\Models\PaymentTopUp;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AccountProfileService;
use App\Services\PayFilmotecaPaymentService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** "Unde beneficiezi de serviciile FILMOTECA.md?" — asked on the web before a top-up. */
class TopUpAccessLocationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccessControlSeeder::class]);
        $this->user = User::query()->create([
            'name' => 'Buyer', 'email' => 'buyer-location@example.com', 'password' => 'password',
            'preferred_locale' => 'ro', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->user->roles()->sync([Role::query()->where('name', 'Viewer')->firstOrFail()->id]);
        app(WalletService::class)->ensureWallet($this->user);
        app(AccountProfileService::class)->ensureDefaultProfile($this->user);
    }

    public function test_an_unknown_location_is_rejected(): void
    {
        [, $token] = PersonalAccessToken::issue($this->user, 'client-test');

        $this->postJson('/api/v1/storefront/wallet/top-ups', [
            'amount' => 200,
            'phone' => '069484967',
            'access_location' => 'mars',
            'billing_address' => [
                'full_name' => 'Buyer', 'country_code' => 'MD', 'city' => 'Chișinău',
                'postal_code' => 'MD-2001', 'address_line1' => 'Strada Test 10',
            ],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('access_location');
    }

    public function test_the_declared_location_reaches_the_wallet_credit(): void
    {
        $wallet = app(WalletService::class)->ensureWallet($this->user);
        $topUp = PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'wallet_id' => $wallet->id,
            'subscriber_id' => 'test-subscriber',
            'amount' => 200,
            'currency' => 'MDL',
            'status' => PaymentTopUp::STATUS_PENDING,
            'access_location' => 'outside_moldova',
        ]);

        $payments = app(PayFilmotecaPaymentService::class);
        (fn () => $this->creditWallet($topUp, [], 'success'))->call($payments);

        $credit = WalletTransaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', WalletTransaction::TYPE_TOP_UP)
            ->sole();
        $this->assertSame('outside_moldova', $credit->access_location);
        $this->assertSame('outside_moldova', $payments->topUpData($topUp->fresh())['access_location']);
    }

    public function test_a_top_up_from_a_client_that_does_not_ask_still_credits(): void
    {
        $wallet = app(WalletService::class)->ensureWallet($this->user);
        $topUp = PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->user->id, 'wallet_id' => $wallet->id, 'subscriber_id' => 'test-subscriber',
            'amount' => 50, 'currency' => 'MDL', 'status' => PaymentTopUp::STATUS_PENDING,
        ]);

        (fn () => $this->creditWallet($topUp, [], 'success'))->call(app(PayFilmotecaPaymentService::class));

        $this->assertNull(WalletTransaction::query()->where('type', WalletTransaction::TYPE_TOP_UP)->sole()->access_location);
    }
}
