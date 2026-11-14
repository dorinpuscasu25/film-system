<?php

namespace Tests\Feature\Api;

use App\Models\BillingAddress;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The billing address can be changed from the profile, not only while topping up. */
class BillingAddressApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccessControlSeeder::class]);
        $this->user = User::query()->create([
            'name' => 'Buyer', 'email' => 'buyer-billing@example.com', 'password' => 'password',
            'preferred_locale' => 'ro', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $this->user->roles()->sync([Role::query()->where('name', 'Viewer')->firstOrFail()->id]);
        app(WalletService::class)->ensureWallet($this->user);
        app(AccountProfileService::class)->ensureDefaultProfile($this->user);
        [, $this->token] = PersonalAccessToken::issue($this->user, 'client-test');
    }

    public function test_a_first_address_is_saved_as_the_default(): void
    {
        $this->putJson('/api/v1/storefront/billing-address', [
            'full_name' => '  Buyer   Test ',
            'country_code' => 'md',
            'city' => 'Chișinău',
            'postal_code' => 'md-2001',
            'address_line1' => 'Strada Test 10',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('billing_address.full_name', 'Buyer Test')
            ->assertJsonPath('billing_address.country_code', 'MD')
            ->assertJsonPath('billing_address.postal_code', 'MD-2001')
            ->assertJsonPath('billing_address.is_default', true);

        $this->getJson('/api/v1/storefront/account', $this->auth())
            ->assertOk()
            ->assertJsonPath('billing_address.city', 'Chișinău');
    }

    public function test_editing_updates_the_existing_address(): void
    {
        $address = BillingAddress::query()->create([
            'user_id' => $this->user->id, 'full_name' => 'Buyer', 'country_code' => 'MD',
            'city' => 'Chișinău', 'postal_code' => 'MD-2001', 'address_line1' => 'Strada Veche 1', 'is_default' => true,
        ]);

        $this->putJson('/api/v1/storefront/billing-address', [
            'id' => $address->id,
            'full_name' => 'Buyer',
            'country_code' => 'RO',
            'city' => 'Iași',
            'postal_code' => '700001',
            'address_line1' => 'Strada Nouă 2',
            'address_line2' => 'ap. 5',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('billing_address.id', $address->id)
            ->assertJsonPath('billing_address.address_line2', 'ap. 5');

        $this->assertSame(1, BillingAddress::query()->where('user_id', $this->user->id)->count());
        $this->assertSame('RO', $address->fresh()->country_code);
    }

    public function test_another_users_address_id_is_not_touched(): void
    {
        $other = User::query()->create([
            'name' => 'Other', 'email' => 'other-billing@example.com', 'password' => 'password',
            'preferred_locale' => 'ro', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        $foreign = BillingAddress::query()->create([
            'user_id' => $other->id, 'full_name' => 'Other', 'country_code' => 'MD',
            'city' => 'Bălți', 'postal_code' => 'MD-3100', 'address_line1' => 'Strada Alta 3', 'is_default' => true,
        ]);

        $this->putJson('/api/v1/storefront/billing-address', [
            'id' => $foreign->id,
            'full_name' => 'Buyer',
            'country_code' => 'MD',
            'city' => 'Chișinău',
            'postal_code' => 'MD-2001',
            'address_line1' => 'Strada Test 10',
        ], $this->auth())->assertOk();

        $this->assertSame('Bălți', $foreign->fresh()->city);
        $this->assertSame(1, BillingAddress::query()->where('user_id', $this->user->id)->count());
    }

    public function test_an_incomplete_address_is_rejected(): void
    {
        $this->putJson('/api/v1/storefront/billing-address', [
            'full_name' => 'Buyer',
            'country_code' => 'Moldova',
            'city' => '',
            'postal_code' => '!!',
            'address_line1' => 'x',
        ], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country_code', 'city', 'postal_code', 'address_line1']);
    }

    /** @return array<string, string> */
    protected function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }
}
