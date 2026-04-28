<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessAdAnalyticsEvent;
use App\Jobs\ProcessVideoAnalyticsEvent;
use App\Models\Content;
use App\Services\AnalyticsBufferService;
use App\Services\BunnyAdWebhookService;
use App\Services\IpGeoLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BunnyWebhookController extends ApiController
{
    public function __construct(
        protected AnalyticsBufferService $buffer,
        protected BunnyAdWebhookService $adWebhook,
        protected IpGeoLocationService $geo,
    ) {}

    /**
     * Bunny Stream calls this on `videoStart`. We respond with the targeted
     * ad payload (pre/mid/post-roll) which Bunny then injects via VMAP.
     * Configure the URL in Bunny Stream Library → Webhook URL.
     */
    public function adInjection(Request $request): JsonResponse
    {
        $contentId = (int) ($request->query('content_id') ?? data_get($request->all(), 'content_id', 0));
        $content = Content::query()->find($contentId);
        if ($content === null) {
            return response()->json(['pre_roll' => null, 'mid_roll' => null, 'post_roll' => null]);
        }

        $countryCode = $this->geo->resolveCountryCode($request);

        $payload = $this->adWebhook->buildAdPayload(
            $content,
            $countryCode,
            (string) ($request->query('group') ?? 'movies'),
            $request->query('session_id'),
            $request->user()?->id,
        );

        return response()->json($payload);
    }

    public function video(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid Bunny webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->claimUniqueDelivery($request, 'bunny-video')) {
            return response()->json(['status' => 'duplicate_ignored'], Response::HTTP_ACCEPTED);
        }

        $payload = $request->all();
        $this->storeDelivery('bunny-video', $request, $payload);

        ProcessVideoAnalyticsEvent::dispatch([
            'content_id' => data_get($payload, 'content_id'),
            'content_format_id' => data_get($payload, 'content_format_id'),
            'event_type' => data_get($payload, 'event_type', 'bunny_video_webhook'),
            'country_code' => data_get($payload, 'country_code'),
            'position_seconds' => data_get($payload, 'position_seconds'),
            'watch_time_seconds' => data_get($payload, 'watch_time_seconds'),
            'bandwidth_gb' => data_get($payload, 'bandwidth_gb', 0),
            'requests_count' => data_get($payload, 'requests_count', 0),
            'cache_hit_rate' => data_get($payload, 'cache_hit_rate'),
            'occurred_at' => data_get($payload, 'occurred_at', now()->toIso8601String()),
            'source_payload' => $payload,
        ]);

        return response()->json(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }

    public function ads(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid Bunny webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->claimUniqueDelivery($request, 'bunny-ads')) {
            return response()->json(['status' => 'duplicate_ignored'], Response::HTTP_ACCEPTED);
        }

        $payload = $request->all();
        $this->storeDelivery('bunny-ads', $request, $payload);

        ProcessAdAnalyticsEvent::dispatch([
            'ad_campaign_id' => data_get($payload, 'ad_campaign_id'),
            'ad_creative_id' => data_get($payload, 'ad_creative_id'),
            'content_id' => data_get($payload, 'content_id'),
            'playback_session_id' => data_get($payload, 'playback_session_id'),
            'event_type' => data_get($payload, 'event_type', 'bunny_ad_webhook'),
            'country_code' => data_get($payload, 'country_code'),
            'occurred_at' => data_get($payload, 'occurred_at', now()->toIso8601String()),
            'source_payload' => $payload,
        ]);

        return response()->json(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }

    protected function storeDelivery(string $source, Request $request, array $payload): void
    {
        $redisPayloadKey = $this->buffer->storeWebhookPayload($source, $payload, $request->headers->all());

        DB::table('webhook_deliveries')->insert([
            'source' => $source,
            'event_type' => (string) data_get($payload, 'event_type', 'unknown'),
            'status' => 'received',
            'signature' => $request->header('X-Bunny-Signature', $request->header('X-Signature')),
            'headers' => json_encode([
                'user-agent' => $request->userAgent(),
                'x-forwarded-for' => $request->header('X-Forwarded-For'),
                'x-bunny-signature' => $request->header('X-Bunny-Signature'),
            ], JSON_THROW_ON_ERROR),
            'payload' => json_encode([
                'redis_payload_key' => $redisPayloadKey,
                'event_type' => data_get($payload, 'event_type'),
            ], JSON_THROW_ON_ERROR),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.bunny.webhook_secret');

        if ($secret === '') {
            return true;
        }

        $signature = (string) ($request->header('X-Bunny-Signature') ?? $request->header('X-Signature') ?? '');

        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    protected function claimUniqueDelivery(Request $request, string $source): bool
    {
        $signature = (string) ($request->header('X-Bunny-Signature') ?? $request->header('X-Signature') ?? 'unsigned');
        $bodyHash = hash('sha256', $request->getContent());

        return $this->buffer->claimWebhookDelivery($source, $signature, $bodyHash);
    }
}
