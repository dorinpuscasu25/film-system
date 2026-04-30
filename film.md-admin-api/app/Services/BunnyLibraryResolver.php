<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentFormat;

/**
 * Resolves the right Bunny Stream API key for a given content_format.
 *
 * Filmoteca runs 2 libraries (kind):
 *   - movies   → feature films (DRM enabled)
 *   - trailers → short previews (no DRM, free)
 *
 * The library_id is stored per-film in content_formats.bunny_library_id when
 * the admin adds the film. The API key is shared across all videos within
 * the same library (set once per kind in .env).
 *
 * Mapping rules:
 *   - format_type 'trailer' or 'preview' → trailers library
 *   - anything else (main / feature / null) → movies library
 *
 * @phpstan-type LibraryConfig array{kind: string, api_key: ?string}
 */
class BunnyLibraryResolver
{
    public const LIBRARY_MOVIES = 'movies';
    public const LIBRARY_TRAILERS = 'trailers';

    /**
     * Pick the API key for a specific content_format. Use this whenever you
     * already have the format object — covers 95% of call sites.
     *
     * @return LibraryConfig
     */
    public function forFormat(ContentFormat $format): array
    {
        return $this->forKind($this->kindFromFormatType((string) $format->format_type));
    }

    /**
     * @return LibraryConfig
     */
    public function forKind(string $kind): array
    {
        $kind = $kind === self::LIBRARY_TRAILERS ? self::LIBRARY_TRAILERS : self::LIBRARY_MOVIES;
        $cfg = (array) (config("services.bunny.libraries.{$kind}") ?? []);

        return [
            'kind' => $kind,
            'api_key' => $cfg['api_key'] ?? null,
        ];
    }

    /**
     * Lookup helper for inbound webhooks where we only know the library_id.
     * Tries movies first (most common). The webhook still validates HMAC.
     *
     * @return LibraryConfig
     */
    public function forLibraryId(string|int $libraryId): array
    {
        // Match against any content_format that has this library_id stored.
        $format = ContentFormat::query()->where('bunny_library_id', (string) $libraryId)->first();
        if ($format !== null) {
            return $this->forFormat($format);
        }

        // No film registered with that library_id yet — assume movies.
        return $this->forKind(self::LIBRARY_MOVIES);
    }

    private function kindFromFormatType(string $formatType): string
    {
        return in_array(strtolower($formatType), ['trailer', 'preview'], true)
            ? self::LIBRARY_TRAILERS
            : self::LIBRARY_MOVIES;
    }
}
