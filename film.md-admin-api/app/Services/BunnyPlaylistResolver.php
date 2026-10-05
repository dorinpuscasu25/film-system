<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentFormat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the real HLS playlist for a Bunny Stream video.
 *
 * Why this exists: the storefront used to hand the web client Bunny's iframe
 * embed URL, so the browser rendered Bunny's player inside an iframe. Nothing
 * outside that iframe can schedule ad breaks, read quartile events, or offer our
 * own subtitle and quality controls — the page simply cannot reach into it.
 *
 * The iOS app never had that problem: it asks Bunny for the playlist and plays it
 * in AVPlayer. This brings the web to the same model, so one ad pipeline and one
 * set of analytics cover every platform.
 *
 * Failure is non-fatal by design: callers fall back to the iframe, which still
 * plays the film (just without our ad breaks).
 */
class BunnyPlaylistResolver
{
    /** Bunny signs playlists for a limited window; stay well inside it. */
    private const CACHE_TTL_SECONDS = 1800;

    public function __construct(
        protected BunnyTokenService $tokens,
    ) {}

    /**
     * @return string|null An `.m3u8` URL, or null when it cannot be resolved.
     */
    public function resolve(ContentFormat $format): ?string
    {
        // An explicitly configured playlist always wins — no API call needed.
        $configured = trim((string) $format->stream_url);
        if ($configured !== '' && str_contains($configured, '.m3u8')) {
            return $configured;
        }

        $libraryId = $format->bunny_library_id;
        $videoId = $format->bunny_video_id;
        if ($libraryId === null || $videoId === null) {
            return null;
        }

        $cacheKey = sprintf('bunny:playlist:%s:%s', $libraryId, $videoId);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($format, $libraryId, $videoId): ?string {
            return $this->fetchPlaylistUrl($format, (string) $libraryId, (string) $videoId);
        });
    }

    private function fetchPlaylistUrl(ContentFormat $format, string $libraryId, string $videoId): ?string
    {
        $base = rtrim((string) config('services.bunny.stream_base_url', ''), '/');
        if ($base === '') {
            return null;
        }

        $query = [];
        // Reuse the same token the signed stream URL carries, when configured.
        $signed = $this->tokens->signedStreamUrl($format);
        $parsed = parse_url($signed, PHP_URL_QUERY);
        if (is_string($parsed) && $parsed !== '') {
            parse_str($parsed, $query);
        }

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->withHeaders(['Referer' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/'])
                ->get("{$base}/library/{$libraryId}/videos/{$videoId}/play", $query);

            if (! $response->successful()) {
                Log::warning('Bunny playlist resolution failed.', [
                    'library_id' => $libraryId,
                    'video_id' => $videoId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $playlistUrl = (string) $response->json('videoPlaylistUrl', '');

            return $playlistUrl !== '' ? $playlistUrl : null;
        } catch (\Throwable $exception) {
            Log::warning('Bunny playlist resolution threw.', [
                'library_id' => $libraryId,
                'video_id' => $videoId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
