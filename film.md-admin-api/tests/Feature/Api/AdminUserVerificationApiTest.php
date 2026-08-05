<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\EmailVerificationCode;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
        $this->admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->adminToken] = PersonalAccessToken::issue($this->admin, 'admin-user-verification-test');
    }

    public function test_admin_can_complete_a_pending_registration_without_the_email_code(): void
    {
        $pendingUser = User::query()->create([
            'name' => 'Utilizator Neconfirmat',
            'email' => 'neconfirmat@example.com',
            'password' => 'password',
            'preferred_locale' => 'ro',
            'status' => 'pending_verification',
            'email_verified_at' => null,
        ]);
        $verification = EmailVerificationCode::query()->create([
            'user_id' => $pendingUser->id,
            'email' => $pendingUser->email,
            'purpose' => EmailVerificationCode::PURPOSE_REGISTRATION,
            'code_hash' => hash('sha256', '123456'),
            'token_hash' => hash('sha256', 'registration-token'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $headers = ['Authorization' => 'Bearer '.$this->adminToken];

        $this->patchJson("/api/v1/admin/users/{$pendingUser->id}/verify-email", [], $headers)
            ->assertOk()
            ->assertJsonPath('already_verified', false)
            ->assertJsonPath('user.email', 'neconfirmat@example.com')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.roles.0.name', 'Viewer')
            ->assertJsonPath('user.wallet.balance_amount', 20)
            ->assertJsonCount(1, 'user.profiles');

        $pendingUser->refresh();
        $this->assertNotNull($pendingUser->email_verified_at);
        $this->assertNotNull($verification->fresh()->consumed_at);
        $this->assertTrue($pendingUser->roles()->where('is_default', true)->exists());
        $this->assertTrue($pendingUser->wallet()->exists());
        $this->assertTrue($pendingUser->profiles()->where('is_default', true)->exists());

        $auditLog = AuditLog::query()
            ->where('action', 'user.email_verified_manually')
            ->where('entity_id', $pendingUser->id)
            ->firstOrFail();
        $this->assertSame($this->admin->id, $auditLog->user_id);

        $this->patchJson("/api/v1/admin/users/{$pendingUser->id}/verify-email", [], $headers)
            ->assertOk()
            ->assertJsonPath('already_verified', true)
            ->assertJsonPath('user.status', 'active');

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'user.email_verified_manually')
            ->where('entity_id', $pendingUser->id)
            ->count());
        $this->assertSame(1, $pendingUser->wallet()->count());
        $this->assertSame(1, $pendingUser->profiles()->count());
    }

    public function test_user_without_admin_access_cannot_verify_an_email(): void
    {
        $viewerRole = Role::query()->where('is_default', true)->firstOrFail();
        $viewer = User::query()->create([
            'name' => 'Viewer',
            'email' => 'viewer-no-admin@example.com',
            'password' => 'password',
            'preferred_locale' => 'ro',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $viewer->roles()->sync([$viewerRole->id]);
        [, $viewerToken] = PersonalAccessToken::issue($viewer, 'viewer-user-verification-test');

        $pendingUser = User::query()->create([
            'name' => 'Pending',
            'email' => 'pending-protected@example.com',
            'password' => 'password',
            'preferred_locale' => 'ro',
            'status' => 'pending_verification',
        ]);

        $this->patchJson("/api/v1/admin/users/{$pendingUser->id}/verify-email", [], [
            'Authorization' => 'Bearer '.$viewerToken,
        ])->assertForbidden();

        $this->assertSame('pending_verification', $pendingUser->fresh()->status);
        $this->assertNull($pendingUser->fresh()->email_verified_at);
    }
}
