<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AdEventTrackingService;
use App\Services\AnalyticsBufferService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAdAnalyticsEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $payload,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(AnalyticsBufferService $buffer, AdEventTrackingService $tracking): void
    {
        $buffer->appendAdEvent($this->payload);

        $campaignId = (int) ($this->payload['ad_campaign_id'] ?? 0);
        if ($campaignId > 0) {
            $tracking->record(
                campaignId: $campaignId,
                eventType: (string) ($this->payload['event_type'] ?? 'unknown'),
                contentId: isset($this->payload['content_id']) ? (int) $this->payload['content_id'] : null,
                userId: isset($this->payload['user_id']) ? (int) $this->payload['user_id'] : null,
                playbackSessionId: $this->payload['playback_session_id'] ?? null,
                countryCode: $this->payload['country_code'] ?? null,
                ipAddress: $this->payload['ip_address'] ?? null,
                userAgent: $this->payload['user_agent'] ?? null,
                meta: (array) ($this->payload['source_payload'] ?? []),
            );
        }
    }
}
