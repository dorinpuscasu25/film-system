<?php

namespace Tests\Feature\Api;

use App\Models\DeviceAuthCode;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
    }

    public function test_full_device_pairing_flow(): void
    {
        // 1. TV requests a pairing code.
        $codeResponse = $this->postJson('/api/v1/auth/device/code', [
            'device_name' => 'Living Room TV',
        ]);

        $codeResponse
            ->assertOk()
            ->assertJsonStructure([
                'device_code', 'user_code', 'verification_uri',
                'verification_uri_complete', 'expires_in', 'interval',
            ]);

        $deviceCode = $codeResponse->json('device_code');
        $userCode = $codeResponse->json('user_code');

        // 2. TV polls — still pending.
        $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode])
            ->assertStatus(202)
            ->assertJsonPath('error', 'authorization_pending');

        // 3. Logged-in user approves the code from their phone/PC.
        [, $token] = $this->createViewerAndToken();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/device/authorize', ['user_code' => $userCode])
            ->assertOk();

        $this->assertDatabaseHas('device_auth_codes', [
            'user_code' => $userCode,
            'status' => DeviceAuthCode::STATUS_APPROVED,
        ]);

        // 4. TV polls again — now gets a real token.
        $tokenResponse = $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode]);

        $tokenResponse
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        // The freshly minted token actually authenticates against the storefront.
        $tvToken = $tokenResponse->json('token');
        $this->withHeaders(['Authorization' => 'Bearer '.$tvToken])
            ->getJson('/api/v1/storefront/account')
            ->assertOk();

        // 5. Re-polling a claimed code does not mint a second token.
        $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode])
            ->assertStatus(202);
    }

    public function test_user_code_is_normalized_when_typed_with_lowercase_and_spaces(): void
    {
        $codeResponse = $this->postJson('/api/v1/auth/device/code');
        $userCode = $codeResponse->json('user_code'); // e.g. "KXTP-9F2L"

        [, $token] = $this->createViewerAndToken();

        // User types it sloppily: lowercase, space instead of dash.
        $sloppy = strtolower(str_replace('-', ' ', $userCode));

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/device/authorize', ['user_code' => $sloppy])
            ->assertOk();
    }

    public function test_expired_code_is_rejected_on_poll(): void
    {
        $codeResponse = $this->postJson('/api/v1/auth/device/code');
        $deviceCode = $codeResponse->json('device_code');

        DeviceAuthCode::query()->latest('id')->first()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode])
            ->assertStatus(410)
            ->assertJsonPath('error', 'expired_token');
    }

    public function test_denied_code_returns_access_denied(): void
    {
        $codeResponse = $this->postJson('/api/v1/auth/device/code');
        $deviceCode = $codeResponse->json('device_code');
        $userCode = $codeResponse->json('user_code');

        [, $token] = $this->createViewerAndToken();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/v1/device/authorize', ['user_code' => $userCode, 'action' => 'deny'])
            ->assertOk();

        $this->postJson('/api/v1/auth/device/token', ['device_code' => $deviceCode])
            ->assertStatus(403)
            ->assertJsonPath('error', 'access_denied');
    }

    /**
     * @return array{0: User, 1: string}
     */
    protected function createViewerAndToken(string $email = 'tv@example.com'): array
    {
        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();

        $user = User::query()->create([
            'name' => 'TV User',
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
