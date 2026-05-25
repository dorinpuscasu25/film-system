<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountProfile;
use App\Models\Content;
use Illuminate\Support\Facades\Cache;

/**
 * Validates PIN unlock and applies age-rating filtering for kids/parental
 * profiles. PINs are 4-6 digits; verified copies are cached briefly so the
 * user doesn't have to re-enter on every request.
 */
class ParentalControlService
{
    public const AGE_RATINGS_ORDERED = ['AG', 'A.P.-12', 'N-15', 'I.M.-18', 'I.M.-18-XXX', 'I.C.'];

    private const LEGACY_RATING_ALIASES = [
        'G' => 'AG',
        'PG' => 'A.P.-12',
        'PG-13' => 'N-15',
        'R' => 'I.M.-18',
        'NC-17' => 'I.M.-18-XXX',
        '0+' => 'AG',
        '6+' => 'AG',
        '12+' => 'A.P.-12',
        '16+' => 'N-15',
        '18+' => 'I.M.-18',
    ];

    public function setPin(AccountProfile $profile, string $pin): void
    {
        $this->assertValidPinFormat($pin);
        $profile->pin_hash = password_hash($pin, PASSWORD_BCRYPT);
        $profile->save();
        $this->forgetUnlock($profile);
    }

    public function clearPin(AccountProfile $profile): void
    {
        $profile->pin_hash = null;
        $profile->save();
        $this->forgetUnlock($profile);
    }

    public function verifyAndUnlock(AccountProfile $profile, string $pin, int $ttlSeconds = 1800): bool
    {
        if (! $profile->checkPin($pin)) {
            return false;
        }
        Cache::put($this->unlockKey($profile), true, $ttlSeconds);

        return true;
    }

    public function isUnlocked(AccountProfile $profile): bool
    {
        if (! $profile->hasPin()) {
            return true;
        }

        return (bool) Cache::get($this->unlockKey($profile), false);
    }

    public function forgetUnlock(AccountProfile $profile): void
    {
        Cache::forget($this->unlockKey($profile));
    }

    public function canAccessContent(AccountProfile $profile, Content $content): bool
    {
        if ($profile->is_kids && $this->ratingExceeds((string) ($content->age_rating ?? 'AG'), 'A.P.-12')) {
            return false;
        }

        $maxRating = $profile->max_age_rating;
        if ($maxRating !== null && $this->ratingExceeds((string) ($content->age_rating ?? 'AG'), $maxRating)) {
            return $this->isUnlocked($profile);
        }

        return true;
    }

    private function ratingExceeds(string $contentRating, string $profileMax): bool
    {
        $contentIdx = $this->ratingIndex($contentRating);
        $maxIdx = $this->ratingIndex($profileMax);

        return $contentIdx > $maxIdx;
    }

    private function ratingIndex(string $rating): int
    {
        $normalizedRating = strtoupper(trim($rating));
        $normalizedRating = self::LEGACY_RATING_ALIASES[$normalizedRating] ?? $normalizedRating;
        $idx = array_search($normalizedRating, self::AGE_RATINGS_ORDERED, true);

        return is_int($idx) ? $idx : 0;
    }

    private function assertValidPinFormat(string $pin): void
    {
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw new \InvalidArgumentException('PIN-ul trebuie să conțină 4-6 cifre.');
        }
    }

    private function unlockKey(AccountProfile $profile): string
    {
        return 'parental:unlocked:'.$profile->getKey();
    }
}
