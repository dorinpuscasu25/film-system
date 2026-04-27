<?php

namespace Tests\Feature\Api;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
    }

    public function test_viewer_can_register_through_the_api(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('email', 'viewer@example.com');

        $pendingUser = User::query()->where('email', 'viewer@example.com')->firstOrFail();
        $verification = EmailVerificationCode::query()
            ->where('user_id', $pendingUser->id)
            ->latest('id')
            ->firstOrFail();

        Mail::assertSent(RegistrationVerificationCodeMail::class, function (RegistrationVerificationCodeMail $mail): bool {
            return $mail->user->email === 'viewer@example.com'
                && preg_match('/^\d{6}$/', $mail->code) === 1;
        });

        $this->assertDatabaseHas('users', [
            'email' => 'viewer@example.com',
            'status' => 'pending_verification',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'viewer@example.com',
            'password' => 'password',
            'app' => 'client',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Confirm your email with the verification code we sent before logging in.');

        /** @var \App\Mail\RegistrationVerificationCodeMail $sentMail */
        $sentMail = collect(Mail::sent(RegistrationVerificationCodeMail::class))->first();

        $verifyResponse = $this->postJson('/api/v1/auth/register/verify', [
            'email' => 'viewer@example.com',
            'code' => $sentMail->code,
        ]);

        $verifyResponse
            ->assertCreated()
            ->assertJsonPath('user.email', 'viewer@example.com')
            ->assertJsonPath('user.roles.0.name', 'Viewer')
            ->assertJsonPath('user.wallet.balance_amount', 100)
            ->assertJsonCount(1, 'user.profiles');

        $this->assertDatabaseHas('users', [
            'email' => 'viewer@example.com',
            'status' => 'active',
        ]);

        $this->assertNotNull($verification->fresh()->consumed_at);
    }

    public function test_pending_registration_can_resend_confirmation_code(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertAccepted();

        /** @var \App\Mail\RegistrationVerificationCodeMail $firstMail */
        $firstMail = collect(Mail::sent(RegistrationVerificationCodeMail::class))->first();

        $this->postJson('/api/v1/auth/register/resend', [
            'email' => 'viewer@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('email', 'viewer@example.com');

        Mail::assertSent(RegistrationVerificationCodeMail::class, 2);

        /** @var \App\Mail\RegistrationVerificationCodeMail $secondMail */
        $secondMail = collect(Mail::sent(RegistrationVerificationCodeMail::class))->last();

        $this->assertNotSame($firstMail->code, $secondMail->code);
    }

    public function test_admin_login_returns_a_bearer_token_and_me_payload(): void
    {
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@filmoteca.md',
            'password' => 'password',
            'app' => 'admin',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('user.admin_panel_access', true);

        $token = $loginResponse->json('token');

        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('user.email', 'admin@filmoteca.md')
            ->assertJsonPath('user.roles.0.name', 'Admin');
    }

    public function test_admin_can_invite_a_user_with_roles(): void
    {
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();
        [$tokenModel, $plainToken] = PersonalAccessToken::issue($admin, 'test-admin');

        $response = $this->postJson('/api/v1/admin/users/invite', [
            'email' => 'invited@example.com',
            'name' => 'Invited User',
            'role_ids' => [$viewerRole->id],
            'expires_in_hours' => 24,
        ], [
            'Authorization' => 'Bearer '.$plainToken,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('invitation.email', 'invited@example.com')
            ->assertJsonPath('invitation.role_names.0', 'Viewer');

        $this->assertDatabaseHas('invitations', [
            'email' => 'invited@example.com',
            'status' => 'pending',
        ]);
    }
}
