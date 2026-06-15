<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\PersonalAccessToken;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\RegistrationCreditService;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCommerceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccessControlSeeder::class,
            TaxonomySeeder::class,
            ContentSeeder::class,
        ]);
    }

    public function test_register_creates_wallet_with_welcome_credit(): void
    {
        $user = $this->createActiveViewer('customer@example.com');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'currency' => 'MDL',
            'balance_amount' => 20.00,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'welcome_bonus',
            'amount' => 20.00,
        ]);
    }

    public function test_registration_credit_campaign_can_override_default_amount(): void
    {
        PlatformSetting::setValue(RegistrationCreditService::SETTINGS_KEY, [
            'enabled' => true,
            'default_amount' => 20,
            'campaigns' => [[
                'label' => 'Mai promo',
                'amount' => 100,
                'starts_at' => now()->subDay()->toDateString(),
                'ends_at' => now()->addDay()->toDateString(),
                'enabled' => true,
            ]],
        ]);

        $user = $this->createActiveViewer('campaign@example.com');

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'currency' => 'MDL',
            'balance_amount' => 100.00,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'welcome_bonus',
            'amount' => 100.00,
        ]);
    }

    public function test_storefront_account_returns_wallet_and_empty_library_for_new_user(): void
    {
        $token = $this->registerViewerAndReturnToken();

        $this->getJson('/api/v1/storefront/account?locale=en', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('wallet.balance_amount', 20)
            ->assertJsonCount(1, 'transactions')
            ->assertJsonCount(0, 'library');
    }

    public function test_purchase_deducts_wallet_and_adds_library_entry(): void
    {
        $token = $this->registerViewerAndReturnToken();
        $user = User::query()->where('email', 'buyer@example.com')->firstOrFail();
        $offer = Offer::query()
            ->where('name', '2 days HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'teambuilding'))
            ->firstOrFail();

        $this->postJson("/api/v1/storefront/offers/{$offer->id}/purchase?locale=en", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('already_owned', false)
            ->assertJsonPath('wallet.balance_amount', 16.01)
            ->assertJsonPath('library_item.content_slug', 'teambuilding')
            ->assertJsonPath('library_item.quality', 'HD');

        $this->assertDatabaseHas('content_entitlements', [
            'user_id' => $user->id,
            'content_id' => $offer->content_id,
            'offer_id' => $offer->id,
            'quality' => 'HD',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $user->id,
            'type' => 'purchase',
            'amount' => -3.99,
        ]);
    }

    public function test_purchase_records_platform_and_own_money_breakdown(): void
    {
        PlatformSetting::setValue(RegistrationCreditService::SETTINGS_KEY, [
            'enabled' => true,
            'default_amount' => 60,
            'campaigns' => [],
        ]);

        $token = $this->registerViewerAndReturnToken(email: 'mixed-buyer@example.com');
        $user = User::query()->where('email', 'mixed-buyer@example.com')->firstOrFail();
        $wallet = $user->wallet()->firstOrFail();
        app(WalletService::class)->credit($wallet, 20, WalletTransaction::TYPE_TOP_UP, 'Manual top-up for test');

        $offer = Offer::query()
            ->where('name', '2 days HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'teambuilding'))
            ->firstOrFail();
        $offer->forceFill(['price_amount' => 70])->save();

        $this->postJson("/api/v1/storefront/offers/{$offer->id}/purchase?locale=en", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('wallet.balance_amount', 10)
            ->assertJsonPath('transaction.meta.funding_source', 'mixed')
            ->assertJsonPath('transaction.meta.platform_amount', 60)
            ->assertJsonPath('transaction.meta.own_amount', 10)
            ->assertJsonPath('transaction.meta.platform_percent', 85.71);
    }

    public function test_billing_excel_export_includes_funding_breakdown_columns(): void
    {
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $token] = PersonalAccessToken::issue($admin, 'test-admin');

        $this->createActiveViewer('export-buyer@example.com');

        $response = $this->postJson('/api/v1/admin/exports', [
            'format' => 'excel',
            'scope' => 'billing',
            'filters' => ['range' => '30days'],
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('job.meta.mime_type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_playback_for_paid_title_requires_access_and_returns_offer_url_after_purchase(): void
    {
        $token = $this->registerViewerAndReturnToken();
        $offer = Offer::query()
            ->where('name', 'Forever Full HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'carbon'))
            ->firstOrFail();
        $offer->content->formats()->create([
            'quality' => 'Full HD',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'carbon-fullhd',
            'stream_url' => 'https://storage.filmoteca.md/playback/carbon-fullhd.mp4',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->getJson('/api/v1/storefront/content/carbon/playback?locale=ro', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();

        $this->postJson("/api/v1/storefront/offers/{$offer->id}/purchase", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->getJson('/api/v1/storefront/content/carbon/playback?locale=ro', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('playback.url', 'https://storage.filmoteca.md/playback/carbon-fullhd.mp4')
            ->assertJsonPath('playback.quality', 'Full HD');
    }

    public function test_kids_profile_cannot_play_content_above_12_plus(): void
    {
        $user = $this->createActiveViewer('kids-playback@example.com');
        [, $token] = PersonalAccessToken::issue($user, 'client-test');
        $kidsProfile = app(AccountProfileService::class)->create($user, [
            'name' => 'Kids',
            'avatar_label' => 'K',
            'is_kids' => true,
        ]);
        $offer = Offer::query()
            ->where('name', 'Forever Full HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'carbon'))
            ->firstOrFail();
        $offer->content->forceFill(['age_rating' => 'I.M.-18'])->save();
        $offer->content->formats()->create([
            'quality' => 'Full HD',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'carbon-fullhd',
            'stream_url' => 'https://storage.filmoteca.md/playback/carbon-fullhd.mp4',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->postJson("/api/v1/storefront/offers/{$offer->id}/purchase", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->getJson("/api/v1/storefront/content/carbon/playback?locale=ro&account_profile_id={$kidsProfile->id}", [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_series_playback_can_resolve_episode_video_for_owned_content(): void
    {
        $token = $this->registerViewerAndReturnToken(email: 'series@example.com');
        $offer = Offer::query()
            ->where('name', '7 days HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'hackerville'))
            ->firstOrFail();
        $offer->content->formats()->create([
            'quality' => 'HD',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'hackerville-hd',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->postJson("/api/v1/storefront/offers/{$offer->id}/purchase", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->getJson('/api/v1/storefront/content/hackerville/playback?locale=en&episode_id=season-1-episode-2', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('episode.episode_number', 2)
            ->assertJsonPath('playback.url', 'https://example.com/video/hackerville-s1e2.mp4');
    }

    protected function registerViewerAndReturnToken(string $email = 'buyer@example.com'): string
    {
        [, $plainToken] = PersonalAccessToken::issue($this->createActiveViewer($email), 'client-test');

        return $plainToken;
    }

    protected function createActiveViewer(string $email): User
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
