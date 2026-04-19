<?php

namespace Tests\Feature\Api;

use App\Models\Content;
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

class PremiereAndAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccessControlSeeder::class,
            TaxonomySeeder::class,
            ContentSeeder::class,
        ]);

        $this->admin = User::query()->where('email', 'admin@film.md')->firstOrFail();
        [, $this->adminToken] = PersonalAccessToken::issue($this->admin, 'test-admin');
    }

    public function test_admin_can_create_content_with_premiere_events(): void
    {
        $response = $this->postJson('/api/v1/admin/content', [
            'type' => Content::TYPE_MOVIE,
            'slug' => 'premiere-film',
            'default_locale' => 'ro',
            'status' => Content::STATUS_PUBLISHED,
            'original_title' => 'Premiere Film',
            'title' => ['ro' => 'Premiere Film', 'ru' => 'Premiere Film', 'en' => 'Premiere Film'],
            'tagline' => ['ro' => 'Tagline', 'ru' => 'Tagline', 'en' => 'Tagline'],
            'short_description' => ['ro' => 'Short', 'ru' => 'Short', 'en' => 'Short'],
            'description' => ['ro' => 'Long description', 'ru' => 'Long description', 'en' => 'Long description'],
            'editor_notes' => ['ro' => '', 'ru' => '', 'en' => ''],
            'meta_title' => ['ro' => '', 'ru' => '', 'en' => ''],
            'meta_description' => ['ro' => '', 'ru' => '', 'en' => ''],
            'poster_url' => 'https://example.com/poster.jpg',
            'backdrop_url' => 'https://example.com/backdrop.jpg',
            'available_qualities' => ['HD'],
            'content_formats' => [[
                'quality' => 'HD',
                'format_type' => 'main',
                'bunny_library_id' => 'lib1',
                'bunny_video_id' => 'vid1',
                'is_active' => true,
                'is_default' => true,
            ]],
            'premiere_events' => [[
                'title' => 'Watch Party Launch',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addHours(2)->toIso8601String(),
                'is_active' => true,
                'is_public' => true,
            ]],
        ], [
            'Authorization' => 'Bearer '.$this->adminToken,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('content.premiere_events.0.title', 'Watch Party Launch');

        $content = Content::query()->where('slug', 'premiere-film')->firstOrFail();

        $this->assertDatabaseHas('premiere_events', [
            'content_id' => $content->id,
            'title' => 'Watch Party Launch',
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    public function test_storefront_playback_is_locked_until_public_premiere_starts(): void
    {
        $viewerToken = $this->registerViewerAndReturnToken();
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $content->premiereEvents()->create([
            'title' => 'Carbon Live Premiere',
            'starts_at' => now()->addHours(4),
            'ends_at' => now()->addHours(6),
            'is_active' => true,
            'is_public' => true,
        ]);

        $offer = Offer::query()
            ->whereHas('content', fn ($query) => $query->where('slug', 'carbon'))
            ->firstOrFail();

        $this->postJson("/api/v1/storefront/offers/{$offer->id}/purchase", [], [
            'Authorization' => 'Bearer '.$viewerToken,
        ])->assertOk();

        $this->getJson('/api/v1/storefront/content/carbon/playback?locale=en', [
            'Authorization' => 'Bearer '.$viewerToken,
        ])
            ->assertStatus(423)
            ->assertJsonPath('premiere_event.title', 'Carbon Live Premiere');
    }

    public function test_admin_audit_log_lists_content_create_action(): void
    {
        $this->postJson('/api/v1/admin/content', [
            'type' => Content::TYPE_MOVIE,
            'slug' => 'audit-film',
            'default_locale' => 'ro',
            'status' => Content::STATUS_DRAFT,
            'original_title' => 'Audit Film',
            'title' => ['ro' => 'Audit Film', 'ru' => 'Audit Film', 'en' => 'Audit Film'],
            'tagline' => ['ro' => 'Tagline', 'ru' => 'Tagline', 'en' => 'Tagline'],
            'short_description' => ['ro' => 'Short', 'ru' => 'Short', 'en' => 'Short'],
            'description' => ['ro' => 'Long description', 'ru' => 'Long description', 'en' => 'Long description'],
            'editor_notes' => ['ro' => '', 'ru' => '', 'en' => ''],
            'meta_title' => ['ro' => '', 'ru' => '', 'en' => ''],
            'meta_description' => ['ro' => '', 'ru' => '', 'en' => ''],
            'poster_url' => 'https://example.com/poster.jpg',
            'backdrop_url' => 'https://example.com/backdrop.jpg',
            'available_qualities' => ['HD'],
            'content_formats' => [[
                'quality' => 'HD',
                'format_type' => 'main',
                'bunny_library_id' => 'lib1',
                'bunny_video_id' => 'vid1',
                'is_active' => true,
                'is_default' => true,
            ]],
        ], [
            'Authorization' => 'Bearer '.$this->adminToken,
        ])->assertCreated();

        $this->getJson('/api/v1/admin/audit-logs?action=content.created', [
            'Authorization' => 'Bearer '.$this->adminToken,
        ])
            ->assertOk()
            ->assertJsonPath('items.0.action', 'content.created');
    }

    protected function registerViewerAndReturnToken(string $email = 'premiere-viewer@example.com'): string
    {
        [, $plainToken] = PersonalAccessToken::issue($this->createActiveViewer($email), 'client-test');

        return $plainToken;
    }

    protected function createActiveViewer(string $email): User
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Premiere Viewer',
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
