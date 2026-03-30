<?php

namespace Tests\Feature\Api;

use App\Models\AccountProfile;
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

class StorefrontProfileApiTest extends TestCase
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

    public function test_profiles_can_be_created_updated_and_deleted(): void
    {
        [$user, $token] = $this->createViewerAndToken();

        $createResponse = $this->postJson('/api/v1/storefront/profiles', [
            'name' => 'Kids',
            'avatar_color' => 'from-green-400 to-emerald-600',
            'avatar_label' => 'K',
            'is_kids' => true,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('profile.name', 'Kids')
            ->assertJsonPath('profile.is_kids', true)
            ->assertJsonCount(2, 'profiles');

        $createdProfileId = (string) $createResponse->json('profile.id');

        $this->patchJson("/api/v1/storefront/profiles/{$createdProfileId}", [
            'name' => 'Kids Updated',
            'avatar_color' => 'from-cyan-500 to-blue-600',
            'avatar_label' => 'KU',
            'is_kids' => false,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('profile.name', 'Kids Updated')
            ->assertJsonPath('profile.is_kids', false);

        $this->deleteJson("/api/v1/storefront/profiles/{$createdProfileId}", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'profiles');

        $this->assertDatabaseMissing('account_profiles', [
            'id' => $createdProfileId,
        ]);
    }

    public function test_profile_favorites_are_tracked_per_profile(): void
    {
        [$user, $token] = $this->createViewerAndToken();
        $defaultProfile = $user->profiles()->firstOrFail();
        $secondaryProfile = app(AccountProfileService::class)->create($user, [
            'name' => 'Evening',
            'avatar_color' => 'from-pink-500 to-rose-500',
            'avatar_label' => 'E',
            'is_kids' => false,
        ]);
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $this->putJson("/api/v1/storefront/profiles/{$secondaryProfile->id}/favorites/{$content->slug}", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath("favorites_by_profile.{$defaultProfile->id}", [])
            ->assertJsonPath("favorites_by_profile.{$secondaryProfile->id}.0", 'carbon');

        $this->getJson('/api/v1/storefront/account?locale=en', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath("favorites_by_profile.{$secondaryProfile->id}.0", 'carbon');

        $this->deleteJson("/api/v1/storefront/profiles/{$secondaryProfile->id}/favorites/{$content->slug}", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath("favorites_by_profile.{$secondaryProfile->id}", []);
    }

    /**
     * @return array{0: User, 1: string}
     */
    protected function createViewerAndToken(string $email = 'profiles@example.com'): array
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Profiles User',
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

        [, $plainToken] = PersonalAccessToken::issue($user->fresh(), 'client-test');

        return [$user->fresh(), $plainToken];
    }
}
