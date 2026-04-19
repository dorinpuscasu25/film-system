<?php

namespace App\Services;

use App\Models\Content;
use App\Models\ContentEntitlement;
use App\Models\CostSettingsVersion;
use App\Models\CreatorMonthlyStatement;
use App\Models\VideoMonthlyCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class AnalyticsBufferService
{
    protected const VIDEO_RAW_LIST = 'analytics:raw:video';
    protected const AD_RAW_LIST = 'analytics:raw:ads';
    protected const VIDEO_DIRTY_SET = 'analytics:aggregate:video:dirty';
    protected const AD_DIRTY_SET = 'analytics:aggregate:ad:dirty';
    protected const WEBHOOK_PREFIX = 'analytics:webhook:';
    protected const WEBHOOK_REPLAY_PREFIX = 'analytics:webhook:replay:';
    protected const MAX_RAW_ITEMS = 10000;

    public function appendVideoEvent(array $payload): void
    {
        $normalized = [
            'content_id' => data_get($payload, 'content_id'),
            'content_format_id' => data_get($payload, 'content_format_id'),
            'playback_session_id' => data_get($payload, 'playback_session_id'),
            'event_type' => (string) data_get($payload, 'event_type', 'unknown'),
            'country_code' => data_get($payload, 'country_code'),
            'position_seconds' => data_get($payload, 'position_seconds'),
            'watch_time_seconds' => (int) data_get($payload, 'watch_time_seconds', 0),
            'bandwidth_gb' => (float) data_get($payload, 'bandwidth_gb', 0),
            'requests_count' => (int) data_get($payload, 'requests_count', 0),
            'cache_hit_rate' => data_get($payload, 'cache_hit_rate'),
            'occurred_at' => (string) data_get($payload, 'occurred_at', now()->toIso8601String()),
            'source_payload' => $payload,
        ];

        $this->appendRaw(self::VIDEO_RAW_LIST, $normalized);
        $this->accumulateVideoAggregate($normalized);
    }

    public function appendAdEvent(array $payload): void
    {
        $normalized = [
            'ad_campaign_id' => data_get($payload, 'ad_campaign_id'),
            'ad_creative_id' => data_get($payload, 'ad_creative_id'),
            'content_id' => data_get($payload, 'content_id'),
            'playback_session_id' => data_get($payload, 'playback_session_id'),
            'event_type' => (string) data_get($payload, 'event_type', 'unknown'),
            'country_code' => data_get($payload, 'country_code'),
            'occurred_at' => (string) data_get($payload, 'occurred_at', now()->toIso8601String()),
            'source_payload' => $payload,
        ];

        $this->appendRaw(self::AD_RAW_LIST, $normalized);
        $this->accumulateAdAggregate($normalized);
    }

    public function storeWebhookPayload(string $source, array $payload, array $headers = []): string
    {
        $key = self::WEBHOOK_PREFIX.$source.':'.now()->format('YmdHis').':'.str()->uuid()->toString();

        Redis::setex($key, 60 * 60 * 24 * 7, json_encode([
            'source' => $source,
            'headers' => $headers,
            'payload' => $payload,
            'received_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $key;
    }

    public function claimWebhookDelivery(string $source, string $signature, string $bodyHash, int $ttlSeconds = 900): bool
    {
        $key = self::WEBHOOK_REPLAY_PREFIX.$source.':'.$signature.':'.$bodyHash;

        return (bool) Redis::set($key, now()->toIso8601String(), 'EX', $ttlSeconds, 'NX');
    }

    public function flushVideoAggregatesToDatabase(): int
    {
        $keys = collect(Redis::smembers(self::VIDEO_DIRTY_SET) ?? []);

        $keys->each(function (string $key): void {
            $hash = Redis::hgetall($key);

            if ($hash === [] || data_get($hash, 'content_id') === null) {
                Redis::srem(self::VIDEO_DIRTY_SET, $key);
                return;
            }

            $cacheSampleCount = max(1, (int) data_get($hash, 'cache_hit_rate_samples', 0));
            $cacheHitRate = data_get($hash, 'cache_hit_rate_sum') !== null
                ? round(((float) data_get($hash, 'cache_hit_rate_sum', 0)) / $cacheSampleCount, 2)
                : null;

            DB::connection('analytics')->table('video_daily_aggregates')->updateOrInsert(
                [
                    'content_id' => (int) $hash['content_id'],
                    'content_format_id' => $this->nullableInt(data_get($hash, 'content_format_id')),
                    'date' => (string) $hash['date'],
                    'country_code' => $this->nullableString(data_get($hash, 'country_code')),
                ],
                [
                    'views' => (int) data_get($hash, 'views', 0),
                    'watch_time_seconds' => (int) data_get($hash, 'watch_time_seconds', 0),
                    'bandwidth_gb' => round((float) data_get($hash, 'bandwidth_gb', 0), 4),
                    'requests_count' => (int) data_get($hash, 'requests_count', 0),
                    'cache_hit_rate' => $cacheHitRate,
                    'meta' => json_encode([
                        'source' => 'redis-buffer',
                        'last_event_type' => data_get($hash, 'last_event_type'),
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            Redis::srem(self::VIDEO_DIRTY_SET, $key);
        });

        return $keys->count();
    }

    public function flushAdAggregatesToDatabase(): int
    {
        $keys = collect(Redis::smembers(self::AD_DIRTY_SET) ?? []);

        $keys->each(function (string $key): void {
            $hash = Redis::hgetall($key);

            if ($hash === [] || data_get($hash, 'ad_campaign_id') === null) {
                Redis::srem(self::AD_DIRTY_SET, $key);
                return;
            }

            DB::connection('analytics')->table('ad_aggregate_daily')->updateOrInsert(
                [
                    'ad_campaign_id' => (int) $hash['ad_campaign_id'],
                    'ad_creative_id' => $this->nullableInt(data_get($hash, 'ad_creative_id')),
                    'date' => (string) $hash['date'],
                    'country_code' => $this->nullableString(data_get($hash, 'country_code')),
                ],
                [
                    'impressions' => (int) data_get($hash, 'impressions', 0),
                    'starts' => (int) data_get($hash, 'starts', 0),
                    'first_quartile' => (int) data_get($hash, 'first_quartile', 0),
                    'midpoint' => (int) data_get($hash, 'midpoint', 0),
                    'third_quartile' => (int) data_get($hash, 'third_quartile', 0),
                    'completes' => (int) data_get($hash, 'completes', 0),
                    'clicks' => (int) data_get($hash, 'clicks', 0),
                    'meta' => json_encode([
                        'source' => 'redis-buffer',
                        'last_event_type' => data_get($hash, 'last_event_type'),
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            Redis::srem(self::AD_DIRTY_SET, $key);
        });

        return $keys->count();
    }

    public function recalculateMonthlyCosts(?string $month = null): array
    {
        $month ??= now()->format('Y-m');
        $dateStart = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        $dateEnd = $dateStart->endOfMonth();

        $settings = CostSettingsVersion::query()
            ->where('is_active', true)
            ->latest('effective_from')
            ->first();

        if ($settings === null) {
            return [
                'month' => $month,
                'videos' => 0,
                'creators' => 0,
            ];
        }

        $dailyRows = collect(DB::connection('analytics')->table('video_daily_aggregates')
            ->whereBetween('date', [$dateStart->toDateString(), $dateEnd->toDateString()])
            ->get())
            ->groupBy(fn ($row) => implode(':', [
                $row->content_id,
                $row->content_format_id ?? '0',
            ]));

        $contentIds = $dailyRows
            ->map(fn (Collection $group) => (int) data_get($group->first(), 'content_id'))
            ->unique()
            ->values();

        $contentMap = Content::query()
            ->with(['formats', 'creators'])
            ->whereIn('id', $contentIds)
            ->get()
            ->keyBy('id');

        $revenueMap = ContentEntitlement::query()
            ->selectRaw('content_id, quality, SUM(price_amount) as revenue_usd')
            ->whereBetween('granted_at', [$dateStart, $dateEnd])
            ->groupBy('content_id', 'quality')
            ->get()
            ->groupBy('content_id');

        $upserts = [];
        $creatorTotals = [];

        foreach ($dailyRows as $key => $rows) {
            $first = $rows->first();
            $contentId = (int) $first->content_id;
            $contentFormatId = $this->nullableInt($first->content_format_id);
            $content = $contentMap->get($contentId);

            if ($content === null) {
                continue;
            }

            $format = $content->formats->firstWhere('id', $contentFormatId);
            $quality = $format?->quality;

            $bandwidth = (float) $rows->sum('bandwidth_gb');
            $watchTimeSeconds = (int) $rows->sum('watch_time_seconds');
            $views = (int) $rows->sum('views');
            $storageDays = $dateEnd->diffInDays($dateStart) + 1;
            $storageGb = (float) data_get($format?->meta ?? [], 'storage_gb', 1);
            $storageCost = round($storageGb * $storageDays * (float) $settings->storage_cost_per_gb_day, 4);
            $deliveryCost = round($bandwidth * (float) $settings->delivery_cost_per_gb, 4);
            $drmCost = round($views * (float) $settings->drm_cost_per_license, 4);

            $revenue = round(
                (float) collect($revenueMap->get($contentId, collect()))
                    ->first(fn ($row) => $quality === null || $row->quality === $quality)?->revenue_usd,
                4,
            );

            $profit = round($revenue - $storageCost - $deliveryCost - $drmCost, 4);

            $upserts[] = [
                'content_id' => $contentId,
                'content_format_id' => $contentFormatId,
                'content_creator_id' => $content->creators->first()?->id,
                'cost_settings_version_id' => $settings->id,
                'month' => $month,
                'storage_cost_usd' => $storageCost,
                'delivery_cost_usd' => $deliveryCost,
                'drm_cost_usd' => $drmCost,
                'revenue_usd' => $revenue,
                'profit_usd' => $profit,
                'usd_to_mdl_rate' => (float) $settings->usd_to_mdl_rate,
                'is_locked' => false,
                'meta' => json_encode([
                    'views' => $views,
                    'watch_time_seconds' => $watchTimeSeconds,
                    'bandwidth_gb' => $bandwidth,
                    'quality' => $quality,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ];

            foreach ($content->creators as $creator) {
                $creatorTotals[$creator->id] ??= [
                    'content_creator_id' => $creator->id,
                    'month' => $month,
                    'revenue_usd' => 0.0,
                    'costs_usd' => 0.0,
                    'payout_usd' => 0.0,
                    'profit_usd' => 0.0,
                    'is_locked' => false,
                    'content_ids' => [],
                ];

                $creatorRevenue = $revenue;
                $creatorCosts = $storageCost + $deliveryCost + $drmCost;
                $platformFeeFactor = max(0, min(100, (float) $creator->platform_fee_percent)) / 100;
                $payout = round($creatorRevenue * (1 - $platformFeeFactor), 4);

                $creatorTotals[$creator->id]['revenue_usd'] += $creatorRevenue;
                $creatorTotals[$creator->id]['costs_usd'] += $creatorCosts;
                $creatorTotals[$creator->id]['payout_usd'] += $payout;
                $creatorTotals[$creator->id]['profit_usd'] += $creatorRevenue - $creatorCosts;
                $creatorTotals[$creator->id]['content_ids'][] = $contentId;
            }
        }

        if ($upserts !== []) {
            VideoMonthlyCost::query()->upsert(
                $upserts,
                ['content_id', 'content_format_id', 'month'],
                ['content_creator_id', 'cost_settings_version_id', 'storage_cost_usd', 'delivery_cost_usd', 'drm_cost_usd', 'revenue_usd', 'profit_usd', 'usd_to_mdl_rate', 'is_locked', 'meta', 'updated_at'],
            );
        }

        if ($creatorTotals !== []) {
            CreatorMonthlyStatement::query()->upsert(
                collect($creatorTotals)->map(function (array $row): array {
                    return [
                        'content_creator_id' => $row['content_creator_id'],
                        'month' => $row['month'],
                        'revenue_usd' => round((float) $row['revenue_usd'], 4),
                        'costs_usd' => round((float) $row['costs_usd'], 4),
                        'payout_usd' => round((float) $row['payout_usd'], 4),
                        'profit_usd' => round((float) $row['profit_usd'], 4),
                        'is_locked' => false,
                        'meta' => json_encode([
                            'content_ids' => array_values(array_unique($row['content_ids'])),
                        ], JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ];
                })->values()->all(),
                ['content_creator_id', 'month'],
                ['revenue_usd', 'costs_usd', 'payout_usd', 'profit_usd', 'is_locked', 'meta', 'updated_at'],
            );
        }

        return [
            'month' => $month,
            'videos' => count($upserts),
            'creators' => count($creatorTotals),
        ];
    }

    protected function appendRaw(string $listKey, array $payload): void
    {
        Redis::rpush($listKey, json_encode($payload, JSON_THROW_ON_ERROR));
        Redis::ltrim($listKey, -self::MAX_RAW_ITEMS, -1);
    }

    protected function accumulateVideoAggregate(array $payload): void
    {
        $occurredAt = CarbonImmutable::parse((string) data_get($payload, 'occurred_at', now()->toIso8601String()));
        $key = $this->videoAggregateKey(
            $occurredAt->toDateString(),
            $this->nullableInt(data_get($payload, 'content_id')) ?? 0,
            $this->nullableInt(data_get($payload, 'content_format_id')) ?? 0,
            $this->nullableString(data_get($payload, 'country_code')) ?? 'GLOBAL',
        );

        Redis::hset($key, 'date', $occurredAt->toDateString());
        Redis::hset($key, 'content_id', (string) ($this->nullableInt(data_get($payload, 'content_id')) ?? 0));
        Redis::hset($key, 'content_format_id', (string) ($this->nullableInt(data_get($payload, 'content_format_id')) ?? 0));
        Redis::hset($key, 'country_code', (string) ($this->nullableString(data_get($payload, 'country_code')) ?? 'GLOBAL'));
        Redis::hincrby($key, 'watch_time_seconds', (int) data_get($payload, 'watch_time_seconds', 0));
        Redis::hincrbyfloat($key, 'bandwidth_gb', (float) data_get($payload, 'bandwidth_gb', 0));
        Redis::hincrby($key, 'requests_count', (int) data_get($payload, 'requests_count', 0));
        Redis::hset($key, 'last_event_type', (string) data_get($payload, 'event_type', 'unknown'));

        if (in_array((string) data_get($payload, 'event_type'), ['play', 'start', 'session_started', 'bunny_video_webhook'], true)) {
            Redis::hincrby($key, 'views', 1);
        }

        if (data_get($payload, 'cache_hit_rate') !== null) {
            Redis::hincrbyfloat($key, 'cache_hit_rate_sum', (float) data_get($payload, 'cache_hit_rate'));
            Redis::hincrby($key, 'cache_hit_rate_samples', 1);
        }

        Redis::sadd(self::VIDEO_DIRTY_SET, $key);
        Redis::expire($key, 60 * 60 * 24 * 45);
    }

    protected function accumulateAdAggregate(array $payload): void
    {
        $occurredAt = CarbonImmutable::parse((string) data_get($payload, 'occurred_at', now()->toIso8601String()));
        $key = $this->adAggregateKey(
            $occurredAt->toDateString(),
            $this->nullableInt(data_get($payload, 'ad_campaign_id')) ?? 0,
            $this->nullableInt(data_get($payload, 'ad_creative_id')) ?? 0,
            $this->nullableString(data_get($payload, 'country_code')) ?? 'GLOBAL',
        );

        $eventType = (string) data_get($payload, 'event_type', 'unknown');

        Redis::hset($key, 'date', $occurredAt->toDateString());
        Redis::hset($key, 'ad_campaign_id', (string) ($this->nullableInt(data_get($payload, 'ad_campaign_id')) ?? 0));
        Redis::hset($key, 'ad_creative_id', (string) ($this->nullableInt(data_get($payload, 'ad_creative_id')) ?? 0));
        Redis::hset($key, 'country_code', (string) ($this->nullableString(data_get($payload, 'country_code')) ?? 'GLOBAL'));
        Redis::hset($key, 'last_event_type', $eventType);

        $field = match ($eventType) {
            'impression' => 'impressions',
            'start' => 'starts',
            'first_quartile' => 'first_quartile',
            'midpoint' => 'midpoint',
            'third_quartile' => 'third_quartile',
            'complete' => 'completes',
            'click' => 'clicks',
            default => null,
        };

        if ($field !== null) {
            Redis::hincrby($key, $field, 1);
        }

        Redis::sadd(self::AD_DIRTY_SET, $key);
        Redis::expire($key, 60 * 60 * 24 * 45);
    }

    protected function videoAggregateKey(string $date, int $contentId, int $contentFormatId, string $countryCode): string
    {
        return "analytics:aggregate:video:{$date}:{$contentId}:{$contentFormatId}:{$countryCode}";
    }

    protected function adAggregateKey(string $date, int $campaignId, int $creativeId, string $countryCode): string
    {
        return "analytics:aggregate:ad:{$date}:{$campaignId}:{$creativeId}:{$countryCode}";
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }

        return (int) $value;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || strtoupper((string) $value) === 'GLOBAL') {
            return null;
        }

        return strtoupper((string) $value);
    }
}
