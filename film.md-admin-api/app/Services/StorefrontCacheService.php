<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class StorefrontCacheService
{
    private const VERSION_KEY = 'storefront:cache-version';

    public function remember(string $scope, array $parts, Closure $resolver, int $ttlSeconds = 300): array
    {
        if (app()->runningUnitTests()) {
            return $resolver();
        }

        $key = $this->key($scope, $parts);

        return Cache::remember($key, $ttlSeconds, fn (): array => $resolver());
    }

    public function clear(): int
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);
        }

        return (int) Cache::increment(self::VERSION_KEY);
    }

    public function version(): int
    {
        $version = Cache::get(self::VERSION_KEY);

        if (is_numeric($version) && (int) $version > 0) {
            return (int) $version;
        }

        Cache::forever(self::VERSION_KEY, 1);

        return 1;
    }

    private function key(string $scope, array $parts): string
    {
        $normalizedParts = collect($parts)
            ->mapWithKeys(fn (mixed $value, string|int $key): array => [(string) $key => $value])
            ->sortKeys()
            ->all();

        return sprintf(
            'storefront:v%d:%s:%s',
            $this->version(),
            $scope,
            hash('sha256', json_encode($normalizedParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        );
    }
}
