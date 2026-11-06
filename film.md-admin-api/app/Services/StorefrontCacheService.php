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

        return Cache::remember($key, $ttlSeconds, fn (): array => $this->plain($resolver()));
    }

    /**
     * The cache store refuses to unserialize objects (`cache.serializable_classes` is false),
     * so a Collection left inside a payload comes back as `__PHP_Incomplete_Class` — the
     * apps then received `{"__PHP_Incomplete_Class_Name": ...}` instead of a genre list.
     * Cache exactly what the endpoint will emit: plain arrays and scalars.
     */
    private function plain(array $payload): array
    {
        return json_decode(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
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
            'storefront:s2:v%d:%s:%s',
            $this->version(),
            $scope,
            hash('sha256', json_encode($normalizedParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        );
    }
}
