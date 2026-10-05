<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Content;

/**
 * Everything the targeting engine needs to know about one ad opportunity.
 *
 * Grouped into an object because the axes keep growing (country, content,
 * maturity rating, viewer profile, platform) and threading eight positional
 * arguments through three services was becoming error-prone.
 */
final class AdRequestContext
{
    public function __construct(
        public readonly Content $content,
        public readonly ?string $countryCode = null,
        public readonly ?string $allowedGroup = 'movies',
        public readonly ?string $playbackSessionId = null,
        public readonly ?int $userId = null,
        public readonly ?string $platform = null,
        public readonly ?string $profileMaxRating = null,
        public readonly bool $isKidsProfile = false,
    ) {}
}
