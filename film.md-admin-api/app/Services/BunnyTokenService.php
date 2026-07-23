<?php

namespace App\Services;

use App\Models\ContentFormat;
use Illuminate\Support\Carbon;

class BunnyTokenService
{
    public function __construct(
        protected BunnyLibraryResolver $libraries,
    ) {}

    public function signedStreamUrl(ContentFormat $format, int $ttlMinutes = 120): string
    {
        if ($format->stream_url) {
            return $format->stream_url;
        }

        $base = rtrim((string) config('services.bunny.stream_base_url', ''), '/');
        $tokenKey = (string) config('services.bunny.token_key', '');
        $pullZone = trim((string) ($format->token_path ?: "{$format->bunny_library_id}/{$format->bunny_video_id}"), '/');

        if ($base === '' || $tokenKey === '') {
            return "{$base}/{$pullZone}";
        }

        $expires = Carbon::now()->addMinutes($ttlMinutes)->timestamp;
        $signature = hash('sha256', $tokenKey.$pullZone.$expires);

        return "{$base}/{$pullZone}?token={$signature}&expires={$expires}";
    }

    /**
     * Generate Bunny's short-lived Embed View token for native mobile players.
     *
     * This is intentionally generated on the backend: the Video Library API
     * key is a secret and must never be shipped in an iOS or Android build.
     *
     * @return array{token: string, expires: int}|null
     */
    public function embedViewToken(
        ContentFormat $format,
        ?string $videoId = null,
        int $ttlSeconds = 300,
    ): ?array {
        $videoId = trim($videoId ?: (string) $format->bunny_video_id);
        $apiKey = (string) ($this->libraries->forFormat($format)['api_key'] ?? '');

        if ($videoId === '' || $apiKey === '') {
            return null;
        }

        $expires = now()->addSeconds(max(60, $ttlSeconds))->timestamp;
        $payload = $apiKey.$videoId.$expires;
        $signature = hash_hmac('sha256', $payload, $apiKey);

        return [
            'token' => base64_encode($signature.':'.$expires),
            'expires' => $expires,
        ];
    }
}
