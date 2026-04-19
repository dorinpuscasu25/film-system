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
        $contentId = $request->query('content_id');

        if ($contentId !== null) {
            $content = Content::query()->find($contentId);
        }

        $campaign = $this->vast->resolveCampaign(
            $content,
            $request->query('country_code'),
            (string) $request->query('group', 'movies'),
            (string) $request->query('placement', 'pre-roll'),
        );

        if ($campaign === null) {
            return response('', Response::HTTP_NO_CONTENT)->header('Content-Type', 'application/xml');
        }

        $trackingBaseUrl = rtrim((string) config('app.url'), '/').'/api/v1/ads/events';
        $xml = $this->vast->buildVastXml($campaign, $trackingBaseUrl);

        return response($xml, Response::HTTP_OK)->header('Content-Type', 'application/xml');
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
