<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\WatchProgress;
use App\Services\AccountProfileService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContinueWatchingApiTest extends TestCase
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

    public function test_free_titles_expire_from_continue_watching_after_seven_days_without_deleting_progress(): void
    {
        [$user, $token] = $this->createViewerAndToken();
        $profile = $user->profiles()->firstOrFail();
        $otherProfile = app(AccountProfileService::class)->create($user, [
            'name' => 'Alt profil',
            'avatar_label' => 'A',
        ]);

        $expiredFree = Content::query()->where('slug', 'afacerea-est')->firstOrFail();
        $recentFree = Content::query()->where('slug', 'carbon')->firstOrFail();
        $oldPaid = Content::query()->where('slug', 'teambuilding')->firstOrFail();
        $completed = Content::query()->where('slug', 'hackerville')->firstOrFail();

        $expiredFree->forceFill(['is_free' => true])->save();
        $recentFree->forceFill(['is_free' => true])->save();
        $oldPaid->forceFill(['is_free' => false])->save();
        $completed->forceFill(['is_free' => false])->save();

        $expiredProgress = $this->createProgress($user, $profile->id, $expiredFree, now()->subDays(8), 420);
        $this->createProgress($user, $profile->id, $recentFree, now()->subDays(6), 180, 'episode-1');
        $this->createProgress($user, $profile->id, $recentFree, now()->subDay(), 360, 'episode-2');
        $this->createProgress($user, $profile->id, $oldPaid, now()->subDays(30), 240);
        $this->createProgress($user, $profile->id, $completed, now()->subHour(), 1200, null, true);
        $this->createProgress($user, $otherProfile->id, $expiredFree, now()->subHour(), 600);

        $response = $this->getJson(
            "/api/v1/storefront/continue-watching?locale=ro&account_profile_id={$profile->id}",
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertOk()->assertJsonCount(2, 'items');

        $slugs = collect($response->json('items'))->pluck('content_slug')->all();

        $this->assertSame(['carbon', 'teambuilding'], $slugs);
        $this->assertSame(1, collect($slugs)->filter(fn (string $slug): bool => $slug === 'carbon')->count());
        $this->assertDatabaseHas('watch_progress', [
            'id' => $expiredProgress->id,
            'position_seconds' => 420,
        ]);

        $expiredFree->formats()->create([
            'quality' => 'HD',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'afacerea-est-hd',
            'stream_url' => 'https://storage.filmoteca.md/playback/afacerea-est-hd.mp4',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->getJson(
            "/api/v1/storefront/content/{$expiredFree->slug}/playback?locale=ro&account_profile_id={$profile->id}",
            ['Authorization' => 'Bearer '.$token],
        )
            ->assertOk()
            ->assertJsonPath('continue_watching.position_seconds', 420)
            ->assertJsonPath('continue_watching.last_watched_at', $expiredProgress->last_watched_at?->toIso8601String());
    }

    public function test_continue_watching_rejects_a_profile_from_another_account(): void
    {
        [, $token] = $this->createViewerAndToken('viewer-one@example.com');
        [$otherUser] = $this->createViewerAndToken('viewer-two@example.com');
        $otherProfile = $otherUser->profiles()->firstOrFail();

        $this->getJson(
            "/api/v1/storefront/continue-watching?account_profile_id={$otherProfile->id}",
            ['Authorization' => 'Bearer '.$token],
        )->assertNotFound();
    }

    protected function createProgress(
        User $user,
        int $profileId,
        Content $content,
        \DateTimeInterface $lastWatchedAt,
        int $positionSeconds,
        ?string $episodeId = null,
        bool $isCompleted = false,
    ): WatchProgress {
        return WatchProgress::query()->create([
            'user_id' => $user->id,
            'account_profile_id' => $profileId,
            'content_id' => $content->id,
            'episode_id' => $episodeId,
            'position_seconds' => $positionSeconds,
            'duration_seconds' => 1800,
            'watch_time_seconds' => $positionSeconds,
            'last_watched_at' => $lastWatchedAt,
            'is_completed' => $isCompleted,
        ]);
    }

    /**
     * @return array{0: User, 1: string}
     */
    protected function createViewerAndToken(string $email = 'continue-watching@example.com'): array
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();

        $user = User::query()->create([
            'name' => 'Continue Watching User',
            'email' => $email,
            'password' => 'password',
            'preferred_locale' => 'ro',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $user->roles()->sync([$viewerRole->id]);
        app(AccountProfileService::class)->ensureDefaultProfile($user);
        [, $plainToken] = PersonalAccessToken::issue($user->fresh(), 'client-test');

        return [$user->fresh(), $plainToken];
    }
}
