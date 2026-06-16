<?php

namespace Tests\Feature\Api;

use App\Models\Content;
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

class StorefrontReviewApiTest extends TestCase
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

    public function test_review_payload_is_scoped_to_one_content_item(): void
    {
        $user = $this->createViewer('reviewer@example.com');
        [, $token] = PersonalAccessToken::issue($user, 'client-test');
        $carbon = Content::query()->where('slug', 'carbon')->firstOrFail();
        $teambuilding = Content::query()->where('slug', 'teambuilding')->firstOrFail();

        $this->postJson('/api/v1/storefront/content/carbon/reviews', [
            'rating' => 5,
            'comment' => 'Kino kino!',
            'locale' => 'ro',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('review.content_id', $carbon->id)
            ->assertJsonPath('summary.count', 1);

        $this->getJson('/api/v1/public/content/carbon/reviews')
            ->assertOk()
            ->assertJsonPath('items.0.content_id', $carbon->id)
            ->assertJsonPath('items.0.comment', 'Kino kino!');

        $this->getJson('/api/v1/public/content/teambuilding/reviews')
            ->assertOk()
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('summary.count', 0);

        $this->assertNotSame($carbon->id, $teambuilding->id);
    }

    protected function createViewer(string $email): User
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Review User',
            'email' => $email,
            'password' => 'password',
            'preferred_locale' => 'ro',
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
