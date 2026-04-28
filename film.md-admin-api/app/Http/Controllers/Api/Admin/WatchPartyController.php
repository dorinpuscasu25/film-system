<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\WatchParty;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use App\Services\WatchPartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class WatchPartyController extends ApiController
{
    public function __construct(
        protected WatchPartyService $service,
        protected ContentScopeService $scope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $assigned = $this->scope->assignedContentIds($user);
        $isScoped = $this->scope->isScoped($user);

        $parties = WatchParty::query()
            ->with('content:id,original_title,slug')
            ->when($isScoped, fn ($q) => $q->whereIn('content_id', $assigned))
            ->orderByDesc('scheduled_start_at')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $parties->map(fn (WatchParty $p) => $this->present($p))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'content_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'scheduled_start_at' => ['required', 'date'],
            'is_public' => ['sometimes', 'boolean'],
            'chat_enabled' => ['sometimes', 'boolean'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
        ]);

        $content = Content::query()->findOrFail($data['content_id']);
        $this->scope->assertCanAccessContent($request->user(), $content);

        $party = $this->service->schedule(
            $content,
            $request->user(),
            $data['title'],
            Carbon::parse($data['scheduled_start_at']),
            (bool) ($data['is_public'] ?? true),
            (bool) ($data['chat_enabled'] ?? true),
            $data['max_participants'] ?? null,
        );

        $this->auditLog->record('watch_party.created', 'watch_party', $party->id, [
            'content_id' => $content->id,
            'starts_at' => $party->scheduled_start_at?->toIso8601String(),
        ], $request->user(), $request);

        return response()->json(['party' => $this->present($party->fresh('content'))], Response::HTTP_CREATED);
    }

    public function start(Request $request, WatchParty $party): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $party->content_id);
        $party = $this->service->start($party);
        $this->auditLog->record('watch_party.started', 'watch_party', $party->id, [], $request->user(), $request);

        return response()->json(['party' => $this->present($party->load('content'))]);
    }

    public function end(Request $request, WatchParty $party): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $party->content_id);
        $party = $this->service->end($party);
        $this->auditLog->record('watch_party.ended', 'watch_party', $party->id, [], $request->user(), $request);

        return response()->json(['party' => $this->present($party->load('content'))]);
    }

    public function destroy(Request $request, WatchParty $party): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $party->content_id);
        $partyId = $party->id;
        $party->delete();
        $this->auditLog->record('watch_party.deleted', 'watch_party', $partyId, [], $request->user(), $request);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    private function present(WatchParty $p): array
    {
        return [
            'id' => $p->id,
            'content_id' => $p->content_id,
            'content_title' => $p->content?->original_title,
            'title' => $p->title,
            'room_code' => $p->room_code,
            'scheduled_start_at' => $p->scheduled_start_at?->toIso8601String(),
            'actual_start_at' => $p->actual_start_at?->toIso8601String(),
            'ended_at' => $p->ended_at?->toIso8601String(),
            'status' => $p->status,
            'is_public' => $p->is_public,
            'chat_enabled' => $p->chat_enabled,
            'max_participants' => $p->max_participants,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
