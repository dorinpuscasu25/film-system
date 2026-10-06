<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessAdAnalyticsEvent;
use App\Models\AccountProfile;
use App\Models\Content;
use App\Services\AdRequestContext;
use App\Services\AdTargetingService;
use App\Services\IpGeoLocationService;
use App\Services\VastService;
use App\Services\VmapService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdsController extends ApiController
{
    public function __construct(
        protected VastService $vast,
        protected VmapService $vmap,
        protected AdTargetingService $targeting,
        protected IpGeoLocationService $geo,
    ) {}

    /**
     * VMAP playlist with every ad break for one playback (pre/mid/post-roll).
     *
     * Preferred over {@see self::vast()} by our own players: one request decides
     * all breaks, so frequency caps are applied consistently and the same
     * advertiser cannot appear twice in a single view.
     */
    public function vmap(Request $request)
    {
        $content = $this->resolveContent($request);

        if ($content === null) {
            return response('', Response::HTTP_NO_CONTENT)->header('Content-Type', 'application/xml');
        }

        $result = $this->vmap->build(
            $this->contextFor($request, $content),
            $this->trackingBaseUrl(),
        );

        return response($result['xml'], Response::HTTP_OK)
            ->header('Content-Type', 'application/xml')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Builds the targeting context from the request.
     *
     * Country comes from the edge headers rather than a query parameter so a
     * client cannot spoof its way into geo-restricted inventory.
     */
    private function contextFor(Request $request, Content $content): AdRequestContext
    {
        $profile = null;
        $profileId = $request->integer('account_profile_id') ?: null;
        if ($profileId !== null && $request->user() !== null) {
            $profile = AccountProfile::query()
                ->where('user_id', $request->user()->id)
                ->find($profileId);
        }

        $platform = strtolower(trim((string) $request->query('platform', '')));

        return new AdRequestContext(
            content: $content,
            countryCode: $this->geo->resolveCountryCode($request),
            allowedGroup: (string) $request->query('group', 'movies'),
            playbackSessionId: $request->query('session') ?: ($request->query('session_id') ?: null),
            userId: $request->user()?->id,
            platform: in_array($platform, AdTargetingService::PLATFORMS, true) ? $platform : null,
            profileMaxRating: $profile?->max_age_rating,
            isKidsProfile: (bool) ($profile?->is_kids ?? false),
        );
    }

    /**
     * Accepts either the numeric content id or the slug, so a caller that only
     * has the catalogue slug still gets correctly targeted ads.
     */
    private function resolveContent(Request $request): ?Content
    {
        $identifier = $request->query('content', $request->query('content_id'));
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $query = Content::query();

        return ctype_digit((string) $identifier)
            ? $query->find((int) $identifier)
            : $query->where('slug', (string) $identifier)->first();
    }

    private function trackingBaseUrl(): string
    {
        // Must match the tracking route; see the note in routes/api.php about
        // why these paths avoid the word "ads".
        return rtrim((string) config('app.url'), '/').'/api/v1/playback/beacon';
    }

    public function vast(Request $request)
    {
        $content = $this->resolveContent($request);

        // Direct campaign lookup, used when the caller already resolved the
        // campaign upstream (e.g. the VMAP playlist) and frequency caps were
        // applied there.
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

        $playbackSessionId = $request->query('session') ?: ($request->integer('session_id') ?: null);
        $xml = $this->vast->buildVastXml(
            $campaign,
            $this->trackingBaseUrl(),
            $playbackSessionId !== null ? (int) $playbackSessionId : null,
            [
                'content_id' => $content?->id,
                'placement' => (string) $request->query('placement', 'pre-roll'),
                'platform' => $request->query('platform'),
            ],
        );

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
            'platform' => $request->query('platform'),
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
            'platform' => data_get($payload, 'platform'),
            'occurred_at' => now()->toIso8601String(),
            'source_payload' => $payload,
        ]);

        return response()->json(['status' => 'accepted'], Response::HTTP_ACCEPTED);
    }
}
