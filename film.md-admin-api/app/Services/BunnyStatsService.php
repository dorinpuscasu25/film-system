<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BunnyCdnStatsDaily;
use App\Models\BunnyStorageSnapshot;
use App\Models\BunnyStreamStatsDaily;
use App\Models\ContentFormat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls statistics from Bunny.net APIs:
 *  - Stream API: per-video views, watch time, country breakdown
 *  - Statistics API: pull zone bandwidth, requests, cache hit rate
 *  - Storage API: storage zone usage snapshot
 *
 * Snapshots are stored in the analytics database. Cost calculation later
 * reads these tables to fill `video_monthly_costs`.
 */
class BunnyStatsService
{
    public function pullStreamStatsForDate(Carbon $date): int
    {
        $apiKey = config('services.bunny.stream_api_key');
        if (empty($apiKey)) {
            Log::warning('BunnyStatsService: BUNNY_STREAM_API_KEY not set, skipping stream stats pull.');
            return 0;
        }

        $base = rtrim((string) config('services.bunny.stats_api_base'), '/');
        $synced = 0;
        $formats = ContentFormat::query()
            ->whereNotNull('bunny_video_id')
            ->where('is_active', true)
            ->get(['id', 'content_id', 'bunny_library_id', 'bunny_video_id']);

        foreach ($formats as $format) {
            try {
                $response = Http::withHeaders([
                    'AccessKey' => $apiKey,
                    'Accept' => 'application/json',
                ])
                    ->timeout(15)
                    ->get(sprintf('%s/library/%s/videos/%s/statistics', $base, $format->bunny_library_id, $format->bunny_video_id), [
                        'dateFrom' => $date->copy()->startOfDay()->toIso8601String(),
                        'dateTo' => $date->copy()->endOfDay()->toIso8601String(),
                    ]);

                if (! $response->successful()) {
                    Log::warning('BunnyStatsService: stream stats request failed', [
                        'video' => $format->bunny_video_id,
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                $data = $response->json();

                BunnyStreamStatsDaily::query()->updateOrCreate(
                    [
                        'bunny_library_id' => $format->bunny_library_id,
                        'bunny_video_id' => $format->bunny_video_id,
                        'date' => $date->toDateString(),
                    ],
                    [
                        'content_id' => $format->content_id,
                        'content_format_id' => $format->id,
                        'views' => (int) ($data['viewsChart'] ?? $data['views'] ?? 0),
                        'watch_time_seconds' => (int) ($data['watchTime'] ?? 0),
                        'plays' => (int) ($data['plays'] ?? 0),
                        'finishes' => (int) ($data['finishes'] ?? 0),
                        'bandwidth_bytes' => (int) ($data['bandwidthUsed'] ?? 0),
                        'country_breakdown' => $data['countryViewCounts'] ?? null,
                        'synced_at' => now(),
                    ],
                );

                $synced++;
            } catch (\Throwable $e) {
                Log::error('BunnyStatsService: stream stats exception', [
                    'video' => $format->bunny_video_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $synced;
    }

    public function pullCdnStatsForDate(Carbon $date): bool
    {
        $apiKey = config('services.bunny.cdn_api_key');
        $pullZoneId = config('services.bunny.cdn_pull_zone_id');

        if (empty($apiKey) || empty($pullZoneId)) {
            Log::warning('BunnyStatsService: CDN api key or pull zone id not set, skipping CDN stats pull.');
            return false;
        }

        $base = rtrim((string) config('services.bunny.stats_api_base'), '/');

        try {
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get($base.'/statistics', [
                    'dateFrom' => $date->copy()->startOfDay()->toIso8601String(),
                    'dateTo' => $date->copy()->endOfDay()->toIso8601String(),
                    'pullZone' => $pullZoneId,
                    'hourly' => 'false',
                ]);

            if (! $response->successful()) {
                Log::warning('BunnyStatsService: CDN stats request failed', ['status' => $response->status()]);
                return false;
            }

            $data = $response->json();

            BunnyCdnStatsDaily::query()->updateOrCreate(
                [
                    'pull_zone_id' => (string) $pullZoneId,
                    'date' => $date->toDateString(),
                ],
                [
                    'bandwidth_bytes' => (int) ($data['totalBandwidthUsed'] ?? 0),
                    'origin_bandwidth_bytes' => (int) ($data['totalOriginTraffic'] ?? 0),
                    'requests_served' => (int) ($data['totalRequestsServed'] ?? 0),
                    'cache_hit_rate' => isset($data['cacheHitRate']) ? round((float) $data['cacheHitRate'], 2) : null,
                    'avg_response_time_ms' => isset($data['averageResponseTime']) ? (int) $data['averageResponseTime'] : null,
                    'geo_breakdown' => $data['geoTrafficDistribution'] ?? null,
                    'synced_at' => now(),
                ],
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('BunnyStatsService: CDN stats exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function pullStorageSnapshotForDate(Carbon $date): bool
    {
        $apiKey = config('services.bunny.storage_api_key');
        $zone = config('services.bunny.storage_zone_name');

        if (empty($apiKey) || empty($zone)) {
            Log::warning('BunnyStatsService: storage api key or zone not set, skipping storage snapshot.');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->get(sprintf('https://api.bunny.net/storagezone'));

            if (! $response->successful()) {
                Log::warning('BunnyStatsService: storage zone request failed', ['status' => $response->status()]);
                return false;
            }

            $zones = collect($response->json())->firstWhere('Name', $zone);
            if ($zones === null) {
                Log::warning('BunnyStatsService: storage zone not found', ['zone' => $zone]);
                return false;
            }

            BunnyStorageSnapshot::query()->updateOrCreate(
                [
                    'storage_zone_name' => (string) $zone,
                    'date' => $date->toDateString(),
                ],
                [
                    'used_bytes' => (int) ($zones['StorageUsed'] ?? 0),
                    'files_count' => (int) ($zones['FilesStored'] ?? 0),
                    'synced_at' => now(),
                ],
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('BunnyStatsService: storage snapshot exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
