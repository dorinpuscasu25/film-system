<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\PlaybackSession;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PlaybackOpsController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $user = request()->user();
        $assignedContentIds = $this->contentScope->assignedContentIds($user);
        $isScoped = $this->contentScope->isScoped($user);

        $sessions = PlaybackSession::query()
            ->with(['user', 'content', 'format'])
            ->when($isScoped, fn ($query) => $query->whereIn('content_id', $assignedContentIds))
            ->latest('started_at')
            ->limit(50)
            ->get();

        return response()->json([
            'stats' => [
                'active_streams' => $sessions->whereIn('status', [PlaybackSession::STATUS_STARTED, PlaybackSession::STATUS_PAUSED])->count(),
                'completed_today' => PlaybackSession::query()->whereDate('ended_at', today())->count(),
                'total_watch_time_seconds' => (int) $sessions->sum('watch_time_seconds'),
            ],
            'sessions' => $sessions->map(fn (PlaybackSession $session) => [
                'id' => $session->id,
                'user_name' => $session->user?->name,
                'user_email' => $session->user?->email,
                'content_title' => $session->content?->original_title,
                'quality' => $session->format?->quality,
                'country_code' => $session->country_code,
                'device_type' => $session->device_type,
                'status' => $session->status,
                'started_at' => $session->started_at?->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'watch_time_seconds' => $session->watch_time_seconds,
                'max_position_seconds' => $session->max_position_seconds,
            ])->values(),
        ]);
    }

    public function revoke(PlaybackSession $playbackSession): JsonResponse
    {
        $this->contentScope->assertCanAccessContent(request()->user(), $playbackSession->content_id);

        $playbackSession->forceFill([
            'status' => PlaybackSession::STATUS_STOPPED,
            'ended_at' => now(),
        ])->save();
        $this->auditLog->record(
            'playback.revoked',
            'playback_session',
            $playbackSession->id,
            ['content_id' => $playbackSession->content_id, 'status' => $playbackSession->status],
            request()->user(),
            request(),
        );

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
