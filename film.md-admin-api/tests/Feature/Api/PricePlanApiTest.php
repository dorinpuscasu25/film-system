<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\Offer;
use App\Models\PersonalAccessToken;
use App\Models\PricePlan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricePlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccessControlSeeder::class, TaxonomySeeder::class, ContentSeeder::class]);

        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $token] = PersonalAccessToken::issue($admin, 'test-admin');
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    protected function standardPlanPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Standard',
            'currency' => 'MDL',
            'items' => [
                ['offer_type' => 'rental', 'quality' => 'HD', 'price_amount' => 39, 'rental_days' => 2],
                ['offer_type' => 'rental', 'quality' => 'Full HD', 'price_amount' => 49, 'rental_days' => 2],
                ['offer_type' => 'lifetime', 'quality' => '4K', 'price_amount' => 129],
            ],
        ], $overrides);
    }

    protected function createPlan(array $overrides = []): PricePlan
    {
        $id = $this->postJson('/api/v1/admin/price-plans', $this->standardPlanPayload($overrides), $this->headers)
            ->assertCreated()
            ->json('plan.id');

        return PricePlan::query()->findOrFail($id);
    }

    public function test_admin_can_create_and_list_price_plans(): void
    {
        $this->createPlan();

        $this->getJson('/api/v1/admin/price-plans', $this->headers)
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Standard')
            ->assertJsonPath('items.0.offers_count', 0)
            ->assertJsonCount(3, 'items.0.items');
    }

    public function test_plan_rejects_duplicate_rows_and_rentals_without_days(): void
    {
        $this->postJson('/api/v1/admin/price-plans', [
            'name' => 'Broken',
            'items' => [
                ['offer_type' => 'rental', 'quality' => 'HD', 'price_amount' => 39, 'rental_days' => 2],
                ['offer_type' => 'rental', 'quality' => 'HD', 'price_amount' => 45, 'rental_days' => 3],
                ['offer_type' => 'rental', 'quality' => '4K', 'price_amount' => 45],
            ],
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.quality', 'items.2.rental_days']);
    }

    public function test_applying_a_plan_reprices_existing_offers_and_creates_playable_ones(): void
    {
        $plan = $this->createPlan();
        $teambuilding = Content::query()->where('slug', 'teambuilding')->firstOrFail();
        $carbon = Content::query()->where('slug', 'carbon')->firstOrFail();
        $carbon->formats()->create([
            'quality' => '4K',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'carbon-4k',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/v1/admin/price-plans/{$plan->id}/apply", [
            'content_ids' => [$teambuilding->id, $carbon->id],
        ], $this->headers)->assertOk();
        $results = collect($response->json('results'))->keyBy('content_id');

        // Teambuilding already sells all three rows → all three are re-priced and linked.
        $this->assertSame(3, $results[$teambuilding->id]['linked']);
        $this->assertDatabaseHas('offers', ['content_id' => $teambuilding->id, 'quality' => 'HD', 'offer_type' => 'rental', 'price_amount' => 39, 'price_plan_id' => $plan->id]);
        $this->assertDatabaseHas('offers', ['content_id' => $teambuilding->id, 'quality' => '4K', 'offer_type' => 'lifetime', 'price_amount' => 129, 'price_plan_id' => $plan->id]);

        // Carbon: HD rental re-priced, Full HD rental skipped (nothing to play), 4K lifetime created from the Bunny format.
        $this->assertSame(1, $results[$carbon->id]['linked']);
        $this->assertSame(1, $results[$carbon->id]['created']);
        $this->assertSame(['Full HD · rental'], $results[$carbon->id]['skipped_qualities']);
        $this->assertDatabaseHas('offers', ['content_id' => $carbon->id, 'quality' => '4K', 'offer_type' => 'lifetime', 'price_amount' => 129, 'price_plan_id' => $plan->id, 'name' => 'Forever 4K']);
        $this->assertDatabaseMissing('offers', ['content_id' => $carbon->id, 'quality' => 'Full HD', 'offer_type' => 'rental']);

        // Offers the plan doesn't price stay untouched and unlinked.
        $this->assertDatabaseHas('offers', ['content_id' => $carbon->id, 'quality' => 'HD', 'offer_type' => 'lifetime', 'price_amount' => 9.99, 'price_plan_id' => null]);
    }

    public function test_changing_the_plan_price_updates_every_linked_offer(): void
    {
        $plan = $this->createPlan();
        $ids = Content::query()->whereIn('slug', ['teambuilding', 'carbon'])->pluck('id')->all();
        $this->postJson("/api/v1/admin/price-plans/{$plan->id}/apply", ['content_ids' => $ids], $this->headers)->assertOk();

        $payload = $this->standardPlanPayload([
            'items' => [
                ['offer_type' => 'rental', 'quality' => 'HD', 'price_amount' => 45, 'rental_days' => 3],
                ['offer_type' => 'rental', 'quality' => 'Full HD', 'price_amount' => 49, 'rental_days' => 2],
                // 4K lifetime row removed → that offer detaches and keeps its price.
            ],
        ]);

        $this->postJson("/api/v1/admin/price-plans/{$plan->id}/preview", $payload, $this->headers)
            ->assertOk()
            ->assertJsonCount(2, 'updated')
            ->assertJsonCount(1, 'detached')
            ->assertJsonPath('unchanged_count', 1);

        $this->patchJson("/api/v1/admin/price-plans/{$plan->id}", $payload, $this->headers)
            ->assertOk()
            ->assertJsonPath('result.updated_offers', 2)
            ->assertJsonPath('result.detached_offers', 1)
            ->assertJsonPath('result.affected_contents', 2);

        foreach ($ids as $contentId) {
            $this->assertDatabaseHas('offers', [
                'content_id' => $contentId, 'quality' => 'HD', 'offer_type' => 'rental',
                'price_amount' => 45, 'rental_days' => 3, 'price_plan_id' => $plan->id,
            ]);
        }
        // Auto-generated names follow the new duration; custom ones (Carbon's "2 days SD") stay.
        $this->assertDatabaseHas('offers', ['content_id' => Content::query()->where('slug', 'teambuilding')->value('id'), 'quality' => 'HD', 'offer_type' => 'rental', 'name' => '3 days HD']);
        $this->assertDatabaseHas('offers', ['quality' => 'HD', 'offer_type' => 'rental', 'name' => '2 days SD', 'rental_days' => 3]);
        $this->assertDatabaseHas('offers', ['quality' => '4K', 'offer_type' => 'lifetime', 'price_amount' => 129, 'price_plan_id' => null]);

        // The storefront reads the new price straight from the offer.
        $this->getJson('/api/v1/public/content/teambuilding')
            ->assertOk()
            ->assertJsonFragment(['quality' => 'HD', 'price_amount' => 45]);
    }

    public function test_linked_offer_takes_price_from_plan_and_rejects_rows_the_plan_lacks(): void
    {
        $plan = $this->createPlan();
        $offer = Offer::query()
            ->whereHas('content', fn ($query) => $query->where('slug', 'teambuilding'))
            ->where('quality', 'HD')
            ->firstOrFail();

        $base = [
            'content_id' => $offer->content_id,
            'offer_type' => 'rental',
            'quality' => 'HD',
            'price_amount' => 1,
            'rental_days' => 9,
            'playback_url' => $offer->playback_url,
            'price_plan_id' => $plan->id,
        ];

        $this->patchJson("/api/v1/admin/offers/{$offer->id}", $base, $this->headers)
            ->assertOk()
            ->assertJsonPath('offer.price_amount', 39)
            ->assertJsonPath('offer.rental_days', 2)
            ->assertJsonPath('offer.price_plan_id', $plan->id);

        // Saving without price_plan_id at all keeps the link.
        $withoutKey = $base;
        unset($withoutKey['price_plan_id']);
        $this->patchJson("/api/v1/admin/offers/{$offer->id}", $withoutKey, $this->headers)
            ->assertOk()
            ->assertJsonPath('offer.price_plan_id', $plan->id)
            ->assertJsonPath('offer.price_amount', 39);

        // Explicit null detaches: the price becomes the one typed in.
        $this->patchJson("/api/v1/admin/offers/{$offer->id}", ['price_plan_id' => null] + $base, $this->headers)
            ->assertOk()
            ->assertJsonPath('offer.price_plan_id', null)
            ->assertJsonPath('offer.price_amount', 1);

        $this->patchJson("/api/v1/admin/offers/{$offer->id}", ['offer_type' => 'lifetime'] + $base, $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_plan_id']);
    }

    public function test_deleting_a_plan_keeps_offer_prices(): void
    {
        $plan = $this->createPlan();
        $teambuilding = Content::query()->where('slug', 'teambuilding')->firstOrFail();
        $this->postJson("/api/v1/admin/price-plans/{$plan->id}/apply", ['content_ids' => [$teambuilding->id]], $this->headers)->assertOk();

        $this->deleteJson("/api/v1/admin/price-plans/{$plan->id}", [], $this->headers)->assertNoContent();

        $this->assertDatabaseMissing('price_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('offers', ['content_id' => $teambuilding->id, 'quality' => 'HD', 'price_amount' => 39, 'price_plan_id' => null]);
    }

    public function test_scoped_producer_cannot_edit_plans(): void
    {
        $plan = $this->createPlan();
        $producer = User::factory()->create(['email' => 'producer-plans@example.com']);
        $producer->roles()->sync([Role::query()->where('name', 'Producer')->firstOrFail()->id]);
        $producer->syncAssignedContentIds([Content::query()->where('slug', 'carbon')->value('id')]);
        [, $token] = PersonalAccessToken::issue($producer->fresh(), 'producer');

        $this->patchJson("/api/v1/admin/price-plans/{$plan->id}", $this->standardPlanPayload(), ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }
}
