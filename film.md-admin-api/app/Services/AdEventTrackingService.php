<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdEvent;
use App\Models\AdEventAggregate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis; // phpcs:ignore -- used for hot-path counters

/**
 * Records ad VAST tracking events into per-event aggregates and a raw log.
 *
 * Writes go through Redis hash buffers (flushed by analytics:flush-buffers
 * scheduled command) to avoid hot-path SQL inserts during VAST tracking pixel
 * requests. The raw log is kept short (last 7 days) for drilldown.
 */
class AdEventTrackingService
{
    public const EVENT_IMPRESSION = 'impression';
    public const EVENT_START = 'start';
    public const EVENT_FIRST_QUARTILE = 'firstQuartile';
    public const EVENT_MIDPOINT = 'midpoint';
    public const EVENT_THIRD_QUARTILE = 'thirdQuartile';
    public const EVENT_COMPLETE = 'complete';
    public const EVENT_PAUSE = 'pause';
    public const EVENT_RESUME = 'resume';
    public const EVENT_MUTE = 'mute';
    public const EVENT_UNMUTE = 'unmute';
    public const EVENT_SKIP = 'skip';
    public const EVENT_CLOSE = 'close';
    public const EVENT_CLICK = 'click';

    public const ALL_EVENTS = [
        self::EVENT_IMPRESSION,
        self::EVENT_START,
        self::EVENT_FIRST_QUARTILE,
        self::EVENT_MIDPOINT,
        self::EVENT_THIRD_QUARTILE,
        self::EVENT_COMPLETE,
        self::EVENT_PAUSE,
        self::EVENT_RESUME,
        self::EVENT_MUTE,
        self::EVENT_UNMUTE,
        self::EVENT_SKIP,
        self::EVENT_CLOSE,
        self::EVENT_CLICK,
    ];

    public function record(
        int $campaignId,
        string $eventType,
        ?int $contentId = null,
        ?int $userId = null,
        ?string $playbackSessionId = null,
        ?string $countryCode = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $meta = [],
    ): void {
        $eventType = in_array($eventType, self::ALL_EVENTS, true) ? $eventType : 'unknown';
        $now = Carbon::now();

        // Buffer aggregate counts in Redis hash (date|event|country)
        $bucketKey = sprintf(
            'ad_agg:%d:%d:%s',
            $campaignId,
            $contentId ?? 0,
            $now->toDateString(),
        );
        $field = $eventType.'|'.($countryCode ?? 'ZZ');
        Redis::hincrby($bucketKey, $field, 1);
        Redis::expire($bucketKey, 86400 * 14); // 14d TTL

        // Raw log (small, used for drilldown). Insert directly — this is
        // the only path that hits SQL during VAST tracking; rate-limited
        // upstream by middleware throttle:600,1.
        AdEvent::query()->create([
            'ad_campaign_id' => $campaignId,
            'content_id' => $contentId,
            'user_id' => $userId,
            'playback_session_id' => $playbackSessionId,
            'event_type' => $eventType,
            'country_code' => $countryCode,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'meta' => $meta,
            'occurred_at' => $now,
        ]);
    }

    /**
     * Flush all buffered ad aggregates from Redis into ad_event_aggregates.
     *
     * Returns number of aggregate keys flushed.
     */
    public function flushAggregatesToDatabase(): int
    {
        $keys = Redis::keys('ad_agg:*');
        if ($keys === []) {
            return 0;
        }

        $flushed = 0;
        foreach ($keys as $key) {
            // Strip Laravel/Redis prefix if present
            $clean = preg_replace('/^.*ad_agg:/', 'ad_agg:', $key) ?? $key;
            $parts = explode(':', $clean);
            if (count($parts) !== 4) {
                continue;
            }
            [, $campaignId, $contentId, $date] = $parts;
            $hash = Redis::hgetall($clean);
            if (! is_array($hash) || $hash === []) {
                continue;
            }

            DB::transaction(function () use ($campaignId, $contentId, $date, $hash): void {
                foreach ($hash as $field => $count) {
                    [$eventType, $country] = explode('|', $field);
                    $aggregate = AdEventAggregate::query()->firstOrCreate(
                        [
                            'ad_campaign_id' => (int) $campaignId,
                            'content_id' => $contentId === '0' ? null : (int) $contentId,
                            'date' => $date,
                            'event_type' => $eventType,
                            'country_code' => $country === 'ZZ' ? null : $country,
                        ],
                        ['count' => 0],
                    );
                    $aggregate->increment('count', (int) $count);

                    // Update campaign-level rollup counters
                    $col = match ($eventType) {
                        self::EVENT_IMPRESSION => 'impressions_count',
                        self::EVENT_COMPLETE => 'completes_count',
                        self::EVENT_CLICK => 'clicks_count',
                        self::EVENT_SKIP => 'skips_count',
                        default => null,
                    };
                    if ($col !== null) {
                        AdCampaign::query()
                            ->where('id', $campaignId)
                            ->increment($col, (int) $count);
                    }
                }
            });

            Redis::del($clean);
            $flushed++;
        }

        return $flushed;
    }
}
