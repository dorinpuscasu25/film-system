<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CacheApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_clear_application_and_cloudflare_caches(): void
    {
        $this->seed(AccessControlSeeder::class);

        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $token] = PersonalAccessToken::issue($admin, 'test-admin');

        config([
            'services.cloudflare.zone_id' => 'zone-id',
            'services.cloudflare.cache_api_token' => 'cache-token',
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('optimize:clear')
            ->andReturn(0);

        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->deleteJson('/api/v1/admin/cache', [], [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('application_cache', 'cleared')
            ->assertJsonPath('cloudflare_cache', 'purged')
            ->assertJsonPath('version', 2);
    }
}
