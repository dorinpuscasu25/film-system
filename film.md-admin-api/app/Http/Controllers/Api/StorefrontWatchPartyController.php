<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\WatchParty;
use App\Services\WatchPartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontWatchPartyController extends ApiController
{
    public function __construct(
        protected WatchPartyService $service,
    ) {}

    public function show(Request $request, string $roomCode): JsonResponse
    {
        $party = WatchParty::query()->where('room_code', strtoupper($roomCode))->with('content')->first();
        if ($party === null || ! $party->is_public) {
            return response()->json(['message' => 'Watch party-ul nu există.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'id' => $party->id,
            'title' => $party->title,
            'room_code' => $party->room_code,
            'status' => $party->status,
            'scheduled_start_at' => $party->scheduled_start_at?->toIso8601String(),
            'actual_start_at' => $party->actual_start_at?->toIso8601String(),
            'current_position_seconds' => $this->service->currentPosition($party),
            'chat_enabled' => $party->chat_enabled,
            'content' => [
                'id' => $party->content?->id,
                'slug' => $party->content?->slug,
                'title' => $party->content?->original_title,
            ],
        ]);
    }

    public function join(Request $request, string $roomCode): JsonResponse
    {
        $party = WatchParty::query()->where('room_code', strtoupper($roomCode))->firstOrFail();
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
        ]);
        $participant = $this->service->join($party, $request->user(), $data['display_name']);

        return response()->json([
            'participant_id' => $participant->id,
            'display_name' => $participant->display_name,
            'is_host' => $participant->is_host,
            'current_position_seconds' => $this->service->currentPosition($party),
        ]);
    }

    public function postChat(Request $request, string $roomCode): JsonResponse
    {
        $party = WatchParty::query()->where('room_code', strtoupper($roomCode))->firstOrFail();
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'min:1', 'max:1000'],
        ]);
        $message = $this->service->postMessage($party, $request->user(), $data['display_name'], $data['body']);

        return response()->json([
            'id' => $message->id,
            'display_name' => $message->display_name,
            'body' => $message->body,
            'sent_at' => $message->sent_at?->toIso8601String(),
        ], Response::HTTP_CREATED);
    }

    public function chatHistory(Request $request, string $roomCode): JsonResponse
    {
        $party = WatchParty::query()->where('room_code', strtoupper($roomCode))->firstOrFail();
        $afterId = (int) $request->query('after_id', 0);

        $messages = $party->chatMessages()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'items' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'display_name' => $m->display_name,
                'body' => $m->body,
                'sent_at' => $m->sent_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
