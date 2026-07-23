<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\PersonalAccessToken;
use App\Models\PlaybackSession;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\StorefrontPurchaseService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        if (! Schema::connection('analytics')->hasTable('video_daily_aggregates')) {
            Schema::connection('analytics')->create('video_daily_aggregates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('content_id');
                $table->unsignedBigInteger('content_format_id')->nullable();
                $table->date('date');
                $table->string('country_code', 5)->nullable();
                $table->unsignedInteger('views')->default(0);
                $table->unsignedInteger('watch_time_seconds')->default(0);
                $table->decimal('bandwidth_gb', 12, 4)->default(0);
                $table->unsignedInteger('requests_count')->default(0);
                $table->decimal('cache_hit_rate', 5, 2)->nullable();
                $table->timestamps();
            });
        }

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

    public function test_analytics_can_filter_one_film_and_combines_sales_views_and_sessions(): void
    {
        $buyer = $this->createViewer('analytics-buyer@example.com');
        $offer = Offer::query()
            ->where('name', '2 days HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'teambuilding'))
            ->firstOrFail();

        app(StorefrontPurchaseService::class)->purchase($buyer, $offer);

        DB::connection('analytics')->table('video_daily_aggregates')->insert([
            'content_id' => $offer->content_id,
            'content_format_id' => null,
            'date' => now()->toDateString(),
            'country_code' => 'MD',
            'views' => 12,
            'watch_time_seconds' => 3600,
            'bandwidth_gb' => 1.25,
            'requests_count' => 20,
            'cache_hit_rate' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        PlaybackSession::query()->create([
            'user_id' => $buyer->id,
            'content_id' => $offer->content_id,
            'offer_id' => $offer->id,
            'session_token' => 'analytics-session-token',
            'country_code' => 'MD',
            'status' => PlaybackSession::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(30),
            'ended_at' => now(),
            'watch_time_seconds' => 1800,
            'max_position_seconds' => 1800,
            'counted_as_view' => true,
        ]);

        $this->getJson(sprintf(
            '/api/v1/admin/analytics?content_id=%d&from=%s&to=%s',
            $offer->content_id,
            now()->toDateString(),
            now()->toDateString(),
        ), [
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->assertOk()
            ->assertJsonPath('stats.sales_count', 1)
            ->assertJsonPath('stats.views_count', 12)
            ->assertJsonPath('stats.sessions_count', 1)
            ->assertJsonPath('content_performance.0.content_id', $offer->content_id)
            ->assertJsonPath('content_performance.0.views_count', 12)
            ->assertJsonPath('country_breakdown.0.country_code', 'MD')
            ->assertJsonCount(1, 'content_performance');
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
