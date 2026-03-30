<?php

namespace Tests\Feature\Api;

use App\Models\Offer;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
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
            'currency' => 'USD',
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
            ->assertJsonPath('wallet.balance_amount', 100)
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
            ->assertJsonPath('wallet.balance_amount', 96.01)
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

    public function test_playback_for_paid_title_requires_access_and_returns_offer_url_after_purchase(): void
    {
        $token = $this->registerViewerAndReturnToken();
        $offer = Offer::query()
            ->where('name', 'Forever Full HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'carbon'))
            ->firstOrFail();

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
            ->assertJsonPath('playback.url', 'https://storage.film.md/playback/carbon-fullhd.mp4')
            ->assertJsonPath('playback.quality', 'Full HD');
    }

    public function test_series_playback_can_resolve_episode_video_for_owned_content(): void
    {
        $token = $this->registerViewerAndReturnToken(email: 'series@example.com');
        $offer = Offer::query()
            ->where('name', '7 days HD')
            ->whereHas('content', fn ($query) => $query->where('slug', 'hackerville'))
            ->firstOrFail();

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
