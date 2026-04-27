<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\StorefrontPurchaseService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
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
        [, $this->token] = PersonalAccessToken::issue($this->admin, 'test-admin');
    }

    public function test_admin_dashboard_returns_sales_analytics_and_recent_transactions(): void
    {
        $buyer = $this->createViewer('buyer@example.com');
        $purchaseService = app(StorefrontPurchaseService::class);
        $rentalOffer = Offer::query()
            ->where('name', '2 days HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'teambuilding'))
            ->firstOrFail();
        $lifetimeOffer = Offer::query()
            ->where('name', 'Forever Full HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'carbon'))
            ->firstOrFail();

        $purchaseService->purchase($buyer, $rentalOffer);
        $purchaseService->purchase($buyer, $lifetimeOffer);

        $this->getJson('/api/v1/admin/dashboard?range=30days', [
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->assertOk()
            ->assertJsonPath('range.value', '30days')
            ->assertJsonPath('stats.period_revenue_amount', 16.98)
            ->assertJsonPath('stats.total_revenue_amount', 16.98)
            ->assertJsonPath('stats.period_orders_count', 2)
            ->assertJsonPath('stats.paid_orders_count', 2)
            ->assertJsonPath('stats.unique_buyers_count', 1)
            ->assertJsonPath('breakdown.rental_orders_count', 1)
            ->assertJsonPath('breakdown.lifetime_orders_count', 1)
            ->assertJsonPath('top_titles.0.slug', 'carbon')
            ->assertJsonPath('recent_transactions.0.type', 'purchase');
    }

    protected function createViewer(string $email): User
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Buyer User',
            'email' => $email,
            'password' => 'password',
            'preferred_locale' => 'en',
            'status' => 'active',
            'email_verified_at' => now(),
            'last_seen_at' => now(),
        ]);

        $user->roles()->sync([$viewerRole->id]);

        app(WalletService::class)->ensureWallet($user);
        app(AccountProfileService::class)->ensureDefaultProfile($user);

        return $user->fresh();
    }
}
