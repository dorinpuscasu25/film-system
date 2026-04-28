<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Content;
use App\Models\User;
use App\Models\WatchParty;
use App\Models\WatchPartyChatMessage;
use App\Models\WatchPartyParticipant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Synchronized "Watch Party" room logic.
 * - Host schedules a party tied to a content_id
 * - Joined participants see a shared playback position derived from the
 *   actual_start_at timestamp (server-authoritative)
 */
class WatchPartyService
{
    public function schedule(
        Content $content,
        ?User $host,
        string $title,
        Carbon $startAt,
        bool $isPublic = true,
        bool $chatEnabled = true,
        ?int $maxParticipants = null,
    ): WatchParty {
        return WatchParty::query()->create([
            'content_id' => $content->id,
            'host_user_id' => $host?->id,
            'title' => $title,
            'room_code' => $this->uniqueRoomCode(),
            'scheduled_start_at' => $startAt,
            'status' => WatchParty::STATUS_SCHEDULED,
            'is_public' => $isPublic,
            'chat_enabled' => $chatEnabled,
            'max_participants' => $maxParticipants,
        ]);
    }

    public function start(WatchParty $party): WatchParty
    {
        if ($party->status !== WatchParty::STATUS_SCHEDULED) {
            throw new HttpException(422, 'Watch party-ul a fost deja pornit sau încheiat.');
        }
        $party->actual_start_at = Carbon::now();
        $party->status = WatchParty::STATUS_LIVE;
        $party->save();

        return $party;
    }

    public function end(WatchParty $party): WatchParty
    {
        $party->ended_at = Carbon::now();
        $party->status = WatchParty::STATUS_ENDED;
        $party->save();

        return $party;
    }

    public function join(WatchParty $party, ?User $user, string $displayName): WatchPartyParticipant
    {
        if ($party->status === WatchParty::STATUS_ENDED || $party->status === WatchParty::STATUS_CANCELLED) {
            throw new HttpException(410, 'Watch party-ul s-a încheiat.');
        }
        if ($party->max_participants !== null) {
            $current = $party->participants()->whereNull('left_at')->count();
            if ($current >= $party->max_participants) {
                throw new HttpException(409, 'Watch party-ul este plin.');
            }
        }

        $existing = WatchPartyParticipant::query()
            ->where('watch_party_id', $party->id)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->first();
        if ($existing !== null) {
            $existing->left_at = null;
            $existing->save();

            return $existing;
        }

        return WatchPartyParticipant::query()->create([
            'watch_party_id' => $party->id,
            'user_id' => $user?->id,
            'display_name' => mb_substr($displayName, 0, 80),
            'joined_at' => Carbon::now(),
            'is_host' => $user !== null && $user->id === $party->host_user_id,
        ]);
    }

    public function leave(WatchParty $party, ?User $user): void
    {
        WatchPartyParticipant::query()
            ->where('watch_party_id', $party->id)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->update(['left_at' => Carbon::now()]);
    }

    public function postMessage(WatchParty $party, ?User $user, string $displayName, string $body): WatchPartyChatMessage
    {
        if (! $party->chat_enabled) {
            throw new HttpException(403, 'Chat-ul este dezactivat pentru acest watch party.');
        }
        $body = trim($body);
        if ($body === '') {
            throw new HttpException(422, 'Mesajul nu poate fi gol.');
        }

        return WatchPartyChatMessage::query()->create([
            'watch_party_id' => $party->id,
            'user_id' => $user?->id,
            'display_name' => mb_substr($displayName, 0, 80),
            'body' => mb_substr($body, 0, 1000),
            'sent_at' => Carbon::now(),
        ]);
    }

    /**
     * Server-authoritative playback position based on actual_start_at.
     */
    public function currentPosition(WatchParty $party): int
    {
        if ($party->actual_start_at === null) {
            return 0;
        }
        $delta = max(0, Carbon::now()->diffInSeconds($party->actual_start_at, false) * -1);

        return (int) $delta;
    }

    private function uniqueRoomCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (WatchParty::query()->where('room_code', $code)->exists());

        return $code;
    }
}
