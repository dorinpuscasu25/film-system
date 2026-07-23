<?php

namespace Tests\Feature\Api;

use App\Models\BillingAddress;
use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccessControlSeeder::class,
            TaxonomySeeder::class,
            ContentSeeder::class,
        ]);

        $this->admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($this->admin, 'accounting-test');
    }

    public function test_accounting_ledger_separates_inflows_refunds_and_billing_market(): void
    {
        [$buyer, $address] = $this->createBuyerWithBillingAddress('buyer-md@example.com', 'MD', 'Chișinău');
        $wallet = $buyer->wallet()->firstOrFail();

        $topUp = PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $buyer->id,
            'wallet_id' => $wallet->id,
            'billing_address_id' => $address->id,
            'subscriber_id' => 'subscriber-accounting',
            'amount' => 300,
            'currency' => 'MDL',
            'status' => PaymentTopUp::STATUS_REFUNDED,
            'provider_order_id' => 'order-accounting',
            'provider_checkout_id' => 'checkout-accounting',
            'provider_rrn' => 'rrn-accounting',
            'billing_address' => $address->only([
                'full_name',
                'country_code',
                'administrative_area',
                'city',
                'postal_code',
                'address_line1',
                'address_line2',
            ]),
            'credited_at' => now()->subDay(),
        ]);

        PaymentRefund::query()->create([
            'uuid' => (string) Str::uuid(),
            'payment_top_up_id' => $topUp->id,
            'user_id' => $buyer->id,
            'wallet_id' => $wallet->id,
            'requested_by_admin_id' => $this->admin->id,
            'provider_order_id' => 'refund-order-accounting',
            'provider_checkout_id' => 'checkout-accounting',
            'provider_rrn' => 'rrn-accounting',
            'amount' => 50,
            'currency' => 'MDL',
            'reason' => 'Test refund',
            'status' => PaymentRefund::STATUS_SUCCEEDED,
            'processed_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/accounting/transactions?status=accounted&country_code=MD', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('summary.gross_inflow', 300)
            ->assertJsonPath('summary.refunds', 50)
            ->assertJsonPath('summary.net_inflow', 250)
            ->assertJsonPath('summary.domestic_transactions', 2)
            ->assertJsonPath('items.0.billing.country_code', 'MD')
            ->assertJsonPath('items.0.billing.market', 'domestic')
            ->assertJsonCount(2, 'items');
    }

    public function test_accounting_excel_contains_billing_columns(): void
    {
        Storage::fake('local');
        [$buyer, $address] = $this->createBuyerWithBillingAddress('excel-md@example.com', 'MD', 'Chișinău');
        $wallet = $buyer->wallet()->firstOrFail();

        PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $buyer->id,
            'wallet_id' => $wallet->id,
            'billing_address_id' => $address->id,
            'subscriber_id' => 'subscriber-excel',
            'amount' => 120,
            'currency' => 'MDL',
            'status' => PaymentTopUp::STATUS_PAID,
            'provider_order_id' => 'order-excel',
            'provider_checkout_id' => 'checkout-excel',
            'provider_rrn' => 'rrn-excel',
            'billing_address' => $address->only([
                'full_name',
                'country_code',
                'administrative_area',
                'city',
                'postal_code',
                'address_line1',
                'address_line2',
            ]),
            'credited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/admin/exports', [
            'format' => 'excel',
            'scope' => 'accounting',
            'filters' => ['status' => 'accounted'],
        ], $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('job.meta.row_count', 1);

        $path = (string) $response->json('job.file_path');
        $contents = Storage::disk('local')->get($path);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'accounting-export-');
        file_put_contents($temporaryPath, $contents);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($temporaryPath) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($temporaryPath);

        $this->assertStringContainsString('billing_country_code', $sheet);
        $this->assertStringContainsString('billing_administrative_area', $sheet);
        $this->assertStringContainsString('billing_address_line1', $sheet);
        $this->assertStringContainsString('Chișinău', $sheet);
    }

    /**
     * @return array{0: User, 1: BillingAddress}
     */
    protected function createBuyerWithBillingAddress(string $email, string $countryCode, string $region): array
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();
        $buyer = User::factory()->create([
            'email' => $email,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $buyer->roles()->sync([$viewerRole->id]);
        app(WalletService::class)->ensureWallet($buyer);
        app(AccountProfileService::class)->ensureDefaultProfile($buyer);

        $address = BillingAddress::query()->create([
            'user_id' => $buyer->id,
            'full_name' => 'Buyer Accounting',
            'country_code' => $countryCode,
            'administrative_area' => $region,
            'city' => 'Chișinău',
            'postal_code' => 'MD-2001',
            'address_line1' => 'Strada Test 1',
            'address_line2' => null,
            'is_default' => true,
        ]);

        return [$buyer->fresh(), $address];
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }
}
