<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\ContentReview;
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

    public function test_user_can_delete_their_own_review(): void
    {
        $user = $this->createViewer('owner@example.com');
        [, $token] = PersonalAccessToken::issue($user, 'client-test');
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $review = ContentReview::query()->create([
            'content_id' => $content->id,
            'user_id' => $user->id,
            'rating' => 5,
            'comment' => 'Recenzia mea.',
            'status' => ContentReview::STATUS_PUBLISHED,
        ]);

        $this->deleteJson("/api/v1/storefront/content/carbon/reviews/{$review->id}", [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('summary.count', 0)
            ->assertJsonPath('summary.average_rating', 0);

        $this->assertDatabaseMissing('content_reviews', ['id' => $review->id]);
        $this->assertNull($content->fresh()->platform_rating);
    }

    public function test_user_can_edit_their_own_review(): void
    {
        $user = $this->createViewer('review-editor@example.com');
        [, $token] = PersonalAccessToken::issue($user, 'client-test');

        $headers = ['Authorization' => 'Bearer '.$token];
        $this->postJson('/api/v1/storefront/content/carbon/reviews', [
            'rating' => 3,
            'comment' => 'Prima versiune a recenziei.',
            'locale' => 'ro',
        ], $headers)->assertOk();

        $this->postJson('/api/v1/storefront/content/carbon/reviews', [
            'rating' => 5,
            'comment' => 'Versiunea actualizată a recenziei.',
            'locale' => 'ro',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('review.rating', 5)
            ->assertJsonPath('review.comment', 'Versiunea actualizată a recenziei.')
            ->assertJsonPath('summary.count', 1);

        $this->assertDatabaseCount('content_reviews', 1);
        $this->assertDatabaseHas('content_reviews', [
            'user_id' => $user->id,
            'rating' => 5,
            'comment' => 'Versiunea actualizată a recenziei.',
        ]);
    }

    public function test_user_cannot_delete_another_users_review(): void
    {
        $owner = $this->createViewer('owner@example.com');
        $otherUser = $this->createViewer('other@example.com');
        [, $token] = PersonalAccessToken::issue($otherUser, 'client-test');
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $review = ContentReview::query()->create([
            'content_id' => $content->id,
            'user_id' => $owner->id,
            'rating' => 4,
            'comment' => 'Recenzia autorului.',
            'status' => ContentReview::STATUS_PUBLISHED,
        ]);

        $this->deleteJson("/api/v1/storefront/content/carbon/reviews/{$review->id}", [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();

        $this->assertDatabaseHas('content_reviews', ['id' => $review->id]);
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
