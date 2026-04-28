<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\AdCampaign;
use App\Models\Content;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdCampaignController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $user = request()->user();
        $isScoped = $this->contentScope->isScoped($user);
        $assignedContentIds = $this->contentScope->assignedContentIds($user);

        $campaigns = AdCampaign::query()
            ->with(['creatives', 'targetingRules.content'])
            ->when($isScoped, function ($query) use ($assignedContentIds): void {
                $query->where(function ($builder) use ($assignedContentIds): void {
                    $builder->whereDoesntHave('targetingRules', fn ($rules) => $rules->whereNotNull('content_id'))
                        ->orWhereHas('targetingRules', fn ($rules) => $rules->whereIn('content_id', $assignedContentIds));
                });
            })
            ->orderByDesc('updated_at')
            ->get();

        $stats = collect(DB::connection('analytics')->table('ad_aggregate_daily')
            ->selectRaw('ad_campaign_id, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(completes) as completes')
            ->groupBy('ad_campaign_id')
            ->get())
            ->keyBy('ad_campaign_id');

        return response()->json([
            'items' => $campaigns->map(function (AdCampaign $campaign) use ($stats): array {
                $aggregate = $stats->get($campaign->id);

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'company_name' => $campaign->company_name,
                    'vast_tag_url' => $campaign->vast_tag_url,
                    'click_through_url' => $campaign->click_through_url,
                    'placement' => $campaign->placement,
                    'status' => $campaign->status,
                    'bid_amount' => $campaign->bid_amount,
                    'skip_offset_seconds' => $campaign->skip_offset_seconds,
                    'starts_at' => $campaign->starts_at?->toIso8601String(),
                    'ends_at' => $campaign->ends_at?->toIso8601String(),
                    'is_active' => $campaign->is_active,
                    'creatives' => $campaign->creatives->map(fn ($creative) => [
                        'id' => $creative->id,
                        'name' => $creative->name,
                        'media_url' => $creative->media_url,
                        'mime_type' => $creative->mime_type,
                        'duration_seconds' => $creative->duration_seconds,
                        'width' => $creative->width,
                        'height' => $creative->height,
                        'is_active' => $creative->is_active,
                    ])->values(),
                    'targeting_rules' => $campaign->targetingRules->map(fn ($rule) => [
                        'id' => $rule->id,
                        'country_code' => $rule->country_code,
                        'allowed_group' => $rule->allowed_group,
                        'content_id' => $rule->content_id,
                        'content_title' => $rule->content?->original_title,
                        'is_include_rule' => $rule->is_include_rule,
                    ])->values(),
                    'stats' => [
                        'impressions' => (int) ($aggregate->impressions ?? 0),
                        'clicks' => (int) ($aggregate->clicks ?? 0),
                        'completes' => (int) ($aggregate->completes ?? 0),
                    ],
                ];
            })->values(),
            'options' => [
                'placements' => ['pre_roll', 'mid_roll', 'post_roll'],
                'statuses' => [
                    AdCampaign::STATUS_DRAFT,
                    AdCampaign::STATUS_ACTIVE,
                    AdCampaign::STATUS_PAUSED,
                    AdCampaign::STATUS_COMPLETED,
                ],
                'allowed_groups' => ['movies', 'trailers'],
                'contents' => Content::query()
                    ->when($isScoped, fn ($query) => $query->whereIn('id', $assignedContentIds))
                    ->orderBy('original_title')
                    ->limit(200)
                    ->get(['id', 'original_title', 'slug'])
                    ->map(fn (Content $content) => [
                        'id' => $content->id,
                        'title' => $content->original_title,
                        'slug' => $content->slug,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->saveCampaign($request);
    }

    public function update(Request $request, AdCampaign $campaign): JsonResponse
    {
        return $this->saveCampaign($request, $campaign);
    }

    public function destroy(AdCampaign $campaign): JsonResponse
    {
        if ($this->contentScope->isScoped(request()->user())) {
            $allowedContentIds = $this->contentScope->assignedContentIds(request()->user());
            $hasForeignRule = $campaign->targetingRules()
                ->whereNotNull('content_id')
                ->whereNotIn('content_id', $allowedContentIds)
                ->exists();

            if ($hasForeignRule) {
                return response()->json([
                    'message' => 'Nu poți șterge o campanie care țintește filme din afara scope-ului tău.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $campaign->delete();
        $this->auditLog->record(
            'ad_campaign.deleted',
            'ad_campaign',
            $campaign->id,
            ['name' => $campaign->name],
            request()->user(),
            request(),
        );

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    protected function saveCampaign(Request $request, ?AdCampaign $campaign = null): JsonResponse
    {
        $user = $request->user();
        $isScoped = $this->contentScope->isScoped($user);
        $assignedContentIds = $this->contentScope->assignedContentIds($user);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'vast_tag_url' => ['nullable', 'url', 'max:2048'],
            'click_through_url' => ['nullable', 'url', 'max:2048'],
            'placement' => ['required', 'string', 'max:32', 'in:pre-roll,mid-roll,post-roll'],
            'status' => ['required', 'string', 'max:32'],
            'bid_amount' => ['nullable', 'numeric', 'min:0'],
            'skip_offset_seconds' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'frequency_cap_per_session' => ['nullable', 'integer', 'min:1', 'max:100'],
            'frequency_cap_per_day' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'mid_roll_offset_seconds' => ['nullable', 'integer', 'min:0'],
            'target_countries' => ['nullable', 'array'],
            'target_countries.*' => ['string', 'max:5'],
            'target_groups' => ['nullable', 'array'],
            'target_groups.*' => ['string', 'max:32'],
            'target_content_ids' => ['nullable', 'array'],
            'target_content_ids.*' => ['integer'],
            'target_excluded_content_ids' => ['nullable', 'array'],
            'target_excluded_content_ids.*' => ['integer'],
            'creatives' => ['nullable', 'array'],
            'creatives.*.name' => ['required', 'string', 'max:255'],
            'creatives.*.media_url' => ['required', 'url', 'max:2048'],
            'creatives.*.mime_type' => ['nullable', 'string', 'max:64'],
            'creatives.*.duration_seconds' => ['nullable', 'integer', 'min:1'],
            'creatives.*.width' => ['nullable', 'integer', 'min:1'],
            'creatives.*.height' => ['nullable', 'integer', 'min:1'],
            'creatives.*.is_active' => ['sometimes', 'boolean'],
            'targeting_rules' => ['nullable', 'array'],
            'targeting_rules.*.country_code' => ['nullable', 'string', 'max:5'],
            'targeting_rules.*.allowed_group' => ['nullable', 'string', 'max:32'],
            'targeting_rules.*.content_id' => ['nullable', 'integer'],
            'targeting_rules.*.is_include_rule' => ['sometimes', 'boolean'],
        ]);

        if ($isScoped) {
            $requestedContentIds = collect($payload['targeting_rules'] ?? [])
                ->pluck('content_id')
                ->filter()
                ->map(fn ($value) => (int) $value)
                ->unique();

            if ($requestedContentIds->diff($assignedContentIds)->isNotEmpty()) {
                return response()->json([
                    'message' => 'Campania conține filme în afara scope-ului acestui utilizator.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $campaign ??= new AdCampaign();
        $campaign->fill([
            'name' => $payload['name'],
            'company_name' => $payload['company_name'] ?? null,
            'vast_tag_url' => $payload['vast_tag_url'] ?? null,
            'click_through_url' => $payload['click_through_url'] ?? null,
            'placement' => $payload['placement'],
            'status' => $payload['status'],
            'bid_amount' => $payload['bid_amount'] ?? 0,
            'skip_offset_seconds' => $payload['skip_offset_seconds'] ?? null,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'is_active' => $payload['is_active'] ?? true,
            'frequency_cap_per_session' => $payload['frequency_cap_per_session'] ?? null,
            'frequency_cap_per_day' => $payload['frequency_cap_per_day'] ?? null,
            'mid_roll_offset_seconds' => $payload['mid_roll_offset_seconds'] ?? null,
            'target_countries' => $payload['target_countries'] ?? null,
            'target_groups' => $payload['target_groups'] ?? null,
            'target_content_ids' => $payload['target_content_ids'] ?? null,
            'target_excluded_content_ids' => $payload['target_excluded_content_ids'] ?? null,
        ])->save();

        $campaign->creatives()->delete();
        foreach ($payload['creatives'] ?? [] as $creative) {
            $campaign->creatives()->create([
                'name' => $creative['name'],
                'media_url' => $creative['media_url'],
                'mime_type' => $creative['mime_type'] ?? 'video/mp4',
                'duration_seconds' => $creative['duration_seconds'] ?? 0,
                'width' => $creative['width'] ?? null,
                'height' => $creative['height'] ?? null,
                'is_active' => $creative['is_active'] ?? true,
            ]);
        }

        $campaign->targetingRules()->delete();
        foreach ($payload['targeting_rules'] ?? [] as $rule) {
            $campaign->targetingRules()->create([
                'country_code' => $rule['country_code'] ?? null,
                'allowed_group' => $rule['allowed_group'] ?? null,
                'content_id' => $rule['content_id'] ?? null,
                'is_include_rule' => $rule['is_include_rule'] ?? true,
            ]);
        }

        $this->auditLog->record(
            $campaign->wasRecentlyCreated ? 'ad_campaign.created' : 'ad_campaign.updated',
            'ad_campaign',
            $campaign->id,
            [
                'name' => $campaign->name,
                'placement' => $campaign->placement,
                'status' => $campaign->status,
            ],
            $request->user(),
            $request,
        );

        return response()->json([
            'campaign' => $campaign->fresh(['creatives', 'targetingRules']),
        ], $campaign->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
