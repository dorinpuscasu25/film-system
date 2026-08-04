<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserWalletApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $viewer;

    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
        $this->admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->adminToken] = PersonalAccessToken::issue($this->admin, 'admin-wallet-test');

        $viewerRole = Role::query()->where('name', 'Viewer')->firstOrFail();
        $this->viewer = User::query()->create([
            'name' => 'Wallet Viewer',
            'email' => 'wallet-viewer@example.com',
            'password' => 'password',
            'preferred_locale' => 'ro',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $this->viewer->roles()->sync([$viewerRole->id]);
        $this->viewer->wallet()->create([
            'currency' => Wallet::DEFAULT_CURRENCY,
            'balance_amount' => 40,
            'meta' => [
                'platform_credit_balance' => 10,
                'own_credit_balance' => 30,
            ],
        ]);
    }

    public function test_admin_can_view_add_and_set_a_user_wallet_balance(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->adminToken];

        $listResponse = $this->getJson('/api/v1/admin/users', $headers)->assertOk();
        $listedViewer = collect($listResponse->json('users'))->firstWhere('id', $this->viewer->id);

        $this->assertNotNull($listedViewer);
        $this->assertSame(40, $listedViewer['wallet']['balance_amount']);

        $this->patchJson("/api/v1/admin/users/{$this->viewer->id}/wallet", [
            'operation' => 'add',
            'amount' => 25,
            'reason' => 'Bonus pentru suport',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('previous_balance', 40)
            ->assertJsonPath('difference', 25)
            ->assertJsonPath('wallet.balance_amount', 65)
            ->assertJsonPath('user.wallet.balance_amount', 65);

        $this->patchJson("/api/v1/admin/users/{$this->viewer->id}/wallet", [
            'operation' => 'set',
            'amount' => 10,
            'reason' => 'Corectare sold',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('previous_balance', 65)
            ->assertJsonPath('difference', -55)
            ->assertJsonPath('wallet.balance_amount', 10);

        $transactions = WalletTransaction::query()
            ->where('user_id', $this->viewer->id)
            ->where('type', WalletTransaction::TYPE_ADJUSTMENT)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $transactions);
        $this->assertSame(25.0, $transactions[0]->amount);
        $this->assertSame(-55.0, $transactions[1]->amount);
        $this->assertSame($this->admin->id, $transactions[0]->meta['admin_user_id']);
        $this->assertSame(10.0, (float) $this->viewer->wallet()->firstOrFail()->balance_amount);
        $this->assertSame(2, AuditLog::query()->where('action', 'wallet.balance_adjusted')->count());
    }

    public function test_user_without_admin_permission_cannot_adjust_wallets(): void
    {
        [, $viewerToken] = PersonalAccessToken::issue($this->viewer, 'viewer-wallet-test');

        $this->patchJson("/api/v1/admin/users/{$this->viewer->id}/wallet", [
            'operation' => 'add',
            'amount' => 50,
        ], [
            'Authorization' => 'Bearer '.$viewerToken,
        ])->assertForbidden();

        $this->assertSame(40.0, (float) $this->viewer->wallet()->firstOrFail()->balance_amount);
    }

    public function test_admin_adjustment_initializes_a_missing_wallet_without_welcome_credit(): void
    {
        $userWithoutWallet = User::query()->create([
            'name' => 'No Wallet User',
            'email' => 'no-wallet@example.com',
            'password' => 'password',
            'preferred_locale' => 'ro',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->patchJson("/api/v1/admin/users/{$userWithoutWallet->id}/wallet", [
            'operation' => 'add',
            'amount' => 30,
        ], [
            'Authorization' => 'Bearer '.$this->adminToken,
        ])
            ->assertOk()
            ->assertJsonPath('previous_balance', 0)
            ->assertJsonPath('wallet.balance_amount', 30);

        $this->assertSame(30.0, (float) $userWithoutWallet->wallet()->firstOrFail()->balance_amount);
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $userWithoutWallet->id,
            'type' => WalletTransaction::TYPE_WELCOME_BONUS,
        ]);
    }

    public function test_wallet_adjustment_rejects_invalid_amounts(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->adminToken];

        $this->patchJson("/api/v1/admin/users/{$this->viewer->id}/wallet", [
            'operation' => 'add',
            'amount' => 0,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->patchJson("/api/v1/admin/users/{$this->viewer->id}/wallet", [
            'operation' => 'set',
            'amount' => -1,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->patchJson("/api/v1/admin/users/{$this->viewer->id}/wallet", [
            'operation' => 'set',
            'amount' => 40,
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }
}
