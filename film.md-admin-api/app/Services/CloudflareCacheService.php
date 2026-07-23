<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CloudflareCacheService
{
    /**
     * Purge the whole Cloudflare zone when cache API credentials are configured.
     *
     * @return 'purged'|'not_configured'
     */
    public function purgeEverything(): string
    {
        $zoneId = trim((string) config('services.cloudflare.zone_id'));
        $apiToken = trim((string) config('services.cloudflare.cache_api_token'));

        if ($zoneId === '' || $apiToken === '') {
            return 'not_configured';
        }

        $response = Http::withToken($apiToken)
            ->acceptJson()
            ->timeout(15)
            ->post(
                sprintf('https://api.cloudflare.com/client/v4/zones/%s/purge_cache', $zoneId),
                ['purge_everything' => true],
            );

        if (! $response->successful() || $response->json('success') !== true) {
            throw new RuntimeException('Cloudflare cache purge request failed.');
        }

        return 'purged';
    }
}
