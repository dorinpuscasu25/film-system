<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessVideoAnalyticsEvent;
use App\Models\Content;
use App\Models\PlaybackSession;
use App\Models\WatchProgress;
use App\Services\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class StorefrontTrackingController extends ApiController
{
    public function __construct(
        protected RecommendationService $recommendations,
    ) {}

    public function startPlaybackSession(Request $request, string $identifier): JsonResponse
    {
        $content = Content::query()
            ->where('slug', $identifier)
            ->orWhere('id', ctype_digit($identifier) ? (int) $identifier : 0)
            ->firstOrFail();

        $session = PlaybackSession::query()->create([
            'user_id' => $request->user()?->id,
            'content_id' => $content->id,
            'content_format_id' => $request->integer('content_format_id') ?: null,
            'offer_id' => $request->integer('offer_id') ?: null,
            'account_profile_id' => $request->integer('account_profile_id') ?: null,
            'session_token' => Str::random(40),
            'country_code' => $request->string('country_code')->toString() ?: null,
            'device_type' => $request->string('device_type')->toString() ?: null,
            'status' => PlaybackSession::STATUS_STARTED,
            'started_at' => now(),
            'meta' => [
                'user_agent' => $request->userAgent(),
                'referrer' => $request->headers->get('referer'),
            ],
        ]);

        ProcessVideoAnalyticsEvent::dispatch([
            'content_id' => $content->id,
            'content_format_id' => $session->content_format_id,
            'playback_session_id' => $session->id,
            'event_type' => 'play',
            'country_code' => $session->country_code,
            'occurred_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'token' => $session->session_token,
                'status' => $session->status,
                'started_at' => $session->started_at?->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    public function updateWatchProgress(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'session_token' => ['required', 'string', 'max:80'],
            'content_id' => ['required', 'integer'],
            'content_format_id' => ['nullable', 'integer'],
            'episode_id' => ['nullable', 'string', 'max:128'],
            'position_seconds' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'watch_time_seconds' => ['nullable', 'integer', 'min:0'],
            'event_type' => ['required', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'max:5'],
        ]);

        $session = PlaybackSession::query()->where('session_token', $payload['session_token'])->firstOrFail();
        $user = $request->user();

        $session->forceFill([
            'status' => match ($payload['event_type']) {
                'pause' => PlaybackSession::STATUS_PAUSED,
                'stop' => PlaybackSession::STATUS_STOPPED,
                'complete' => PlaybackSession::STATUS_COMPLETED,
                default => PlaybackSession::STATUS_STARTED,
            },
            'ended_at' => in_array($payload['event_type'], ['stop', 'complete'], true) ? now() : $session->ended_at,
            'watch_time_seconds' => max((int) $session->watch_time_seconds, (int) ($payload['watch_time_seconds'] ?? 0)),
            'max_position_seconds' => max((int) $session->max_position_seconds, (int) ($payload['position_seconds'] ?? 0)),
            'counted_as_view' => $session->counted_as_view || $payload['event_type'] === 'stop' || $payload['event_type'] === 'complete',
        ])->save();

        WatchProgress::query()->updateOrCreate(
            [
                'user_id' => $user?->id,
                'account_profile_id' => $session->account_profile_id,
                'content_id' => $payload['content_id'],
                'episode_id' => $payload['episode_id'] ?? null,
            ],
            [
                'content_format_id' => $payload['content_format_id'] ?? null,
                'position_seconds' => (int) ($payload['position_seconds'] ?? 0),
                'duration_seconds' => (int) ($payload['duration_seconds'] ?? 0),
                'watch_time_seconds' => (int) ($payload['watch_time_seconds'] ?? 0),
                'last_watched_at' => now(),
                'is_completed' => $payload['event_type'] === 'complete',
                'meta' => ['session_token' => $payload['session_token']],
            ],
        );

        ProcessVideoAnalyticsEvent::dispatch([
            'content_id' => $payload['content_id'],
            'content_format_id' => $payload['content_format_id'] ?? null,
            'playback_session_id' => $session->id,
            'event_type' => $payload['event_type'],
            'country_code' => $payload['country_code'] ?? $session->country_code,
            'position_seconds' => $payload['position_seconds'] ?? 0,
            'watch_time_seconds' => $payload['watch_time_seconds'] ?? 0,
            'occurred_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'watch_time_seconds' => $session->watch_time_seconds,
                'max_position_seconds' => $session->max_position_seconds,
            ],
        ]);
    }

    public function continueWatching(Request $request): JsonResponse
    {
        $items = WatchProgress::query()
            ->with('content')
            ->where('user_id', $request->user()?->id)
            ->latest('last_watched_at')
            ->limit(12)
            ->get();

        return response()->json([
            'items' => $items->map(fn (WatchProgress $item) => [
                'content_id' => $item->content_id,
                'content_slug' => $item->content?->slug,
                'title' => $item->content?->original_title,
                'poster_url' => $item->content?->poster_url,
                'position_seconds' => $item->position_seconds,
                'duration_seconds' => $item->duration_seconds,
                'progress_percent' => $item->duration_seconds > 0
                    ? round(($item->position_seconds / $item->duration_seconds) * 100, 2)
                    : 0,
                'last_watched_at' => $item->last_watched_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function recommendations(Request $request, string $identifier): JsonResponse
    {
        $content = Content::query()
            ->with('taxonomies')
            ->where('slug', $identifier)
            ->orWhere('id', ctype_digit($identifier) ? (int) $identifier : 0)
            ->firstOrFail();

        $items = $this->recommendations->related($content, $request->user()?->id);

        return response()->json([
            'items' => $items->map(fn (Content $item) => [
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->original_title,
                'poster_url' => $item->poster_url,
                'backdrop_url' => $item->backdrop_url,
                'type' => $item->type,
            ])->values(),
        ]);
    }
}
