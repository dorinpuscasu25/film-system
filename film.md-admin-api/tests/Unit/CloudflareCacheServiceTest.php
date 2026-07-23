<?php

namespace Tests\Unit;

use App\Services\CloudflareCacheService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareCacheServiceTest extends TestCase
{
    public function test_it_skips_purge_when_cloudflare_is_not_configured(): void
    {
        config([
            'services.cloudflare.zone_id' => null,
            'services.cloudflare.cache_api_token' => null,
        ]);

        $this->assertSame('not_configured', app(CloudflareCacheService::class)->purgeEverything());

        Http::assertNothingSent();
    }

    public function test_it_purges_the_configured_cloudflare_zone(): void
    {
        config([
            'services.cloudflare.zone_id' => 'zone-id',
            'services.cloudflare.cache_api_token' => 'cache-token',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $this->assertSame('purged', app(CloudflareCacheService::class)->purgeEverything());

        Http::assertSent(fn ($request): bool => $request->url()
            === 'https://api.cloudflare.com/client/v4/zones/zone-id/purge_cache'
            && $request->hasHeader('Authorization', 'Bearer cache-token')
            && $request['purge_everything'] === true);
    }
}
