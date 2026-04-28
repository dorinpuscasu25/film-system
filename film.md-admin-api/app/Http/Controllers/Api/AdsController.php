<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessAdAnalyticsEvent;
use App\Models\Content;
use App\Services\VastService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdsController extends ApiController
{
    public function __construct(
        protected VastService $vast,
    ) {}

    public function vast(Request $request)
    {
        $content = null;
        $contentId = $request->query('content', $request->query('content_id'));

        if ($contentId !== null) {
            $content = Content::query()->find($contentId);
        }

        // Direct campaign lookup (used by BunnyAdWebhookService when it has
        // already chosen the campaign upstream, with frequency caps applied).
        $explicitCampaignId = $request->integer('campaign') ?: null;
        if ($explicitCampaignId !== null) {
            $campaign = \App\Models\AdCampaign::query()->with('creatives')->find($explicitCampaignId);
        } else {
            $campaign = $this->vast->resolveCampaign(
                $content,
                $request->query('country_code'),
                (string) $request->query('group', 'movies'),
                (string) $request->query('placement', 'pre-roll'),
            );
        }

        if ($campaign === null) {
            return response('', Response::HTTP_NO_CONTENT)->header('Content-Type', 'application/xml');
        }

        $trackingBaseUrl = rtrim((string) config('app.url'), '/').'/api/v1/ads/track';
        $playbackSessionId = $request->query('session') ?: ($request->integer('session_id') ?: null);
        $xml = $this->vast->buildVastXml($campaign, $trackingBaseUrl, $playbackSessionId);

        return response($xml, Response::HTTP_OK)->header('Content-Type', 'application/xml');
    }

    /**
     * Tracking pixel endpoint hit by VAST-compliant players (GET).
     * Returns a 1x1 transparent GIF and dispatches the event for buffering.
     */
    public function track(Request $request)
    {
        ProcessAdAnalyticsEvent::dispatch([
            'ad_campaign_id' => $request->integer('campaign_id') ?: null,
            'ad_creative_id' => $request->integer('creative_id') ?: null,
            'content_id' => $request->integer('content_id') ?: null,
            'playback_session_id' => $request->integer('session_id') ?: null,
            'event_type' => (string) $request->query('event', 'unknown'),
            'country_code' => $request->query('country_code'),
            'occurred_at' => now()->toIso8601String(),
            'source_payload' => $request->query(),
        ]);

        // 1x1 transparent GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, Response::HTTP_OK)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function event(Request $request)
    {
        $payload = $request->all();
        ProcessAdAnalyticsEvent::dispatch([
            'ad_campaign_id' => data_get($payload, 'campaign_id'),
            'ad_creative_id' => data_get($payload, 'creative_id'),
            'content_id' => data_get($payload, 'content_id'),
            'playback_session_id' => data_get($payload, 'playback_session_id'),
            'event_type' => data_get($payload, 'event', data_get($payload, 'event_type', 'unknown')),
            'country_code' => data_get($payload, 'country_code'),
            'occurred_at' => now()->toIso8601String(),
            'source_payload' => $payload,
        ]);

        return response()->json(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }
}
