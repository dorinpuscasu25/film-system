<?php

namespace Tests\Feature\Api;

use App\Models\AdCampaign;
use App\Models\Content;
use App\Models\Offer;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProducerAccessApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $producer;

    protected Content $assignedContent;

    protected Content $foreignContent;

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
                $table->date('date');
                $table->string('country_code', 5)->nullable();
                $table->unsignedInteger('views')->default(0);
                $table->unsignedInteger('watch_time_seconds')->default(0);
                $table->decimal('bandwidth_gb', 12, 4)->default(0);
            });
        }

        $contents = Content::query()->orderBy('id')->take(2)->get();
        $this->assignedContent = $contents->firstOrFail();
        $this->foreignContent = $contents->skip(1)->firstOrFail();
        $producerRole = Role::query()->where('name', 'Producer')->firstOrFail();

        $this->producer = User::factory()->create([
            'email' => 'producer@example.com',
            'status' => 'active',
        ]);
        $this->producer->roles()->sync([$producerRole->id]);
        $this->producer->syncAssignedContentIds([$this->assignedContent->id]);
        [, $this->token] = PersonalAccessToken::issue($this->producer->fresh(), 'producer-test');
    }

    public function test_producer_can_only_list_and_open_assigned_content(): void
    {
        $headers = $this->authHeaders();

        $this->getJson('/api/v1/admin/content', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $this->assignedContent->id);

        $this->getJson("/api/v1/admin/content/{$this->assignedContent->id}", $headers)
            ->assertOk()
            ->assertJsonPath('content.id', $this->assignedContent->id);

        $this->getJson("/api/v1/admin/content/{$this->foreignContent->id}", $headers)
            ->assertForbidden();
    }

    public function test_producer_can_view_only_assigned_content_financials_without_global_billing_access(): void
    {
        $headers = $this->authHeaders();

        $this->getJson("/api/v1/admin/content/{$this->assignedContent->id}/financials", $headers)
            ->assertOk()
            ->assertJsonPath('content_id', $this->assignedContent->id);

        $this->getJson("/api/v1/admin/content/{$this->foreignContent->id}/financials", $headers)
            ->assertForbidden();

        $this->getJson('/api/v1/admin/financial-summary', $headers)->assertForbidden();
        $this->getJson('/api/v1/admin/payments/top-ups', $headers)->assertForbidden();
    }

    public function test_scoped_dashboard_does_not_expose_platform_wide_access_control_or_wallet_totals(): void
    {
        $this->getJson('/api/v1/admin/dashboard', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('stats.users_total', 0)
            ->assertJsonPath('stats.admins_total', 0)
            ->assertJsonPath('stats.roles_total', 0)
            ->assertJsonPath('stats.pending_invitations', 0)
            ->assertJsonPath('stats.wallet_balance_total', 0)
            ->assertJsonPath('summary.catalog_titles_total', 1);
    }

    public function test_scoped_commerce_permissions_cannot_bypass_offer_content_scope(): void
    {
        $producerRole = Role::query()->where('name', 'Producer')->firstOrFail();
        $producerRole->permissions()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('code', ['commerce.view', 'commerce.edit_offers'])
                ->pluck('id')
                ->all(),
        );

        $assignedOffersCount = Offer::query()
            ->where('content_id', $this->assignedContent->id)
            ->count();
        $foreignOffer = Offer::query()
            ->where('content_id', $this->foreignContent->id)
            ->firstOrFail();

        $this->getJson('/api/v1/admin/offers', $this->authHeaders())
            ->assertOk()
            ->assertJsonCount($assignedOffersCount, 'items');

        $this->patchJson("/api/v1/admin/offers/{$foreignOffer->id}", [
            'content_id' => $foreignOffer->content_id,
            'name' => $foreignOffer->name,
            'offer_type' => $foreignOffer->offer_type,
            'quality' => $foreignOffer->quality,
            'currency' => $foreignOffer->currency,
            'price_amount' => $foreignOffer->price_amount,
            'playback_url' => $foreignOffer->playback_url,
            'rental_days' => $foreignOffer->rental_days,
            'is_active' => $foreignOffer->is_active,
            'starts_at' => $foreignOffer->starts_at,
            'ends_at' => $foreignOffer->ends_at,
            'sort_order' => $foreignOffer->sort_order,
        ], $this->authHeaders())->assertForbidden();
    }

    public function test_advertising_views_and_drilldowns_are_limited_to_accessible_campaigns_and_content(): void
    {
        $assignedCampaign = $this->createCampaign('Assigned campaign');
        $assignedCampaign->targetingRules()->create([
            'content_id' => $this->assignedContent->id,
            'is_include_rule' => true,
        ]);
        $foreignCampaign = $this->createCampaign('Foreign campaign');
        $foreignCampaign->targetingRules()->create([
            'content_id' => $this->foreignContent->id,
            'is_include_rule' => true,
        ]);
        $globalCampaign = $this->createCampaign('Global campaign');

        $response = $this->getJson('/api/v1/admin/ad-campaigns', $this->authHeaders())
            ->assertOk()
            ->assertJsonCount(2, 'items');
        $visibleIds = collect($response->json('items'))->pluck('id')->all();

        $this->assertContains($assignedCampaign->id, $visibleIds);
        $this->assertContains($globalCampaign->id, $visibleIds);
        $this->assertNotContains($foreignCampaign->id, $visibleIds);

        $this->getJson("/api/v1/admin/ad-campaigns/{$foreignCampaign->id}/stats", $this->authHeaders())
            ->assertForbidden();
        $this->getJson("/api/v1/admin/ad-campaigns/{$foreignCampaign->id}/events", $this->authHeaders())
            ->assertForbidden();
        $this->postJson('/api/v1/admin/ad-test/resolve', [
            'content_id' => $this->foreignContent->id,
            'placement' => 'pre-roll',
        ], $this->authHeaders())->assertForbidden();
    }

    public function test_invited_producer_receives_selected_content_when_accepting_invitation(): void
    {
        Mail::fake();
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        $producerRole = Role::query()->where('name', 'Producer')->firstOrFail();
        [, $adminToken] = PersonalAccessToken::issue($admin, 'invite-producer-test');

        $inviteResponse = $this->postJson('/api/v1/admin/users/invite', [
            'email' => 'invited-producer@example.com',
            'name' => 'Invited Producer',
            'role_ids' => [$producerRole->id],
            'assigned_content_ids' => [$this->assignedContent->id],
            'expires_in_hours' => 24,
        ], [
            'Authorization' => 'Bearer '.$adminToken,
        ])->assertCreated()
            ->assertJsonPath('invitation.assigned_content_ids.0', $this->assignedContent->id);

        parse_str((string) parse_url((string) $inviteResponse->json('accept_url'), PHP_URL_QUERY), $query);
        $invitationToken = (string) ($query['token'] ?? '');
        $this->assertNotSame('', $invitationToken);

        $acceptResponse = $this->postJson("/api/v1/invites/{$invitationToken}/accept", [
            'name' => 'Invited Producer',
            'password' => 'producer-password',
            'password_confirmation' => 'producer-password',
            'preferred_locale' => 'ro',
        ])->assertOk()
            ->assertJsonPath('user.content_scope_assigned', true)
            ->assertJsonPath('user.assigned_content_ids.0', $this->assignedContent->id);

        $this->getJson('/api/v1/admin/content', [
            'Authorization' => 'Bearer '.$acceptResponse->json('token'),
        ])->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $this->assignedContent->id);
    }

    public function test_suspended_user_cannot_continue_using_an_existing_token(): void
    {
        $this->producer->forceFill(['status' => 'suspended'])->save();

        $this->getJson('/api/v1/auth/me', $this->authHeaders())
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is not active.');
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function createCampaign(string $name): AdCampaign
    {
        return AdCampaign::query()->create([
            'name' => $name,
            'placement' => AdCampaign::PLACEMENT_PRE_ROLL,
            'status' => AdCampaign::STATUS_ACTIVE,
            'bid_amount' => 1,
            'is_active' => true,
        ]);
    }
}
