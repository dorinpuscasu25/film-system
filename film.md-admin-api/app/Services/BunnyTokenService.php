<?php

namespace App\Services;

use App\Models\ContentFormat;
use Illuminate\Support\Carbon;

class BunnyTokenService
{
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
}
