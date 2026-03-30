<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferApiTest extends TestCase
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

        $this->admin = User::query()->where('email', 'admin@film.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($this->admin, 'test-admin');
    }

    public function test_admin_can_list_offers(): void
    {
        $this->getJson('/api/v1/admin/offers', [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertOk()
            ->assertJsonFragment(['content_title' => 'Carbon'])
            ->assertJsonPath('stats.total_offers', 9);
    }

    public function test_admin_can_create_offer_for_content(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $this->postJson('/api/v1/admin/offers', [
            'content_id' => $content->id,
            'offer_type' => 'rental',
            'quality' => 'Full HD',
            'price_amount' => 6.99,
            'playback_url' => 'https://cdn.example.com/carbon-full-hd.mp4',
            'currency' => 'usd',
            'rental_days' => 5,
            'is_active' => true,
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertCreated()
            ->assertJsonPath('offer.content_id', $content->id)
            ->assertJsonPath('offer.access_label', '5 days')
            ->assertJsonPath('offer.price_amount', 6.99)
            ->assertJsonPath('offer.playback_url', 'https://cdn.example.com/carbon-full-hd.mp4');

        $this->assertDatabaseHas('offers', [
            'content_id' => $content->id,
            'offer_type' => 'rental',
            'quality' => 'Full HD',
            'rental_days' => 5,
            'playback_url' => 'https://cdn.example.com/carbon-full-hd.mp4',
        ]);
    }

    public function test_movie_offer_requires_playback_url(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $this->postJson('/api/v1/admin/offers', [
            'content_id' => $content->id,
            'offer_type' => 'lifetime',
            'quality' => 'HD',
            'price_amount' => 12.99,
            'currency' => 'usd',
            'is_active' => true,
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['playback_url']);
    }
}
