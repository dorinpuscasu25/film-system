<?php

namespace App\Services;

use App\Models\Content;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps `contents.imdb_rating` in step with IMDb, from the title's IMDb id.
 *
 * Ratings come from OMDb (omdbapi.com): its free key allows 1,000 requests a day, plenty for
 * a daily pass over the catalogue. IMDb's own datasets are licensed for non-commercial use
 * only, and scraping imdb.com breaks its terms, so neither is used.
 */
class ImdbRatingService
{
    private const ENDPOINT = 'https://www.omdbapi.com/';

    public function isConfigured(): bool
    {
        return filled(config('services.omdb.key'));
    }

    /**
     * Accepts a bare id ("tt0111161") or any IMDb link
     * ("https://www.imdb.com/title/tt0111161/?ref_=..."); null if neither.
     */
    public static function parseImdbId(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        return preg_match('/\b(tt\d{7,10})\b/i', $input, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * Brings one title's rating up to date. A title without an IMDb id loses its rating (it can
     * no longer be typed by hand). When OMDb can't be reached, the current rating is kept.
     *
     * @return bool Whether the stored rating changed.
     */
    public function sync(Content $content): bool
    {
        if (blank($content->imdb_id)) {
            if ($content->imdb_rating === null && $content->imdb_rating_synced_at === null) {
                return false;
            }
            $content->forceFill(['imdb_rating' => null, 'imdb_rating_synced_at' => null])->saveQuietly();

            return true;
        }

        $result = $this->fetch($content->imdb_id);
        if ($result === false) {
            return false;
        }

        $previous = $content->imdb_rating;
        $content->forceFill(['imdb_rating' => $result, 'imdb_rating_synced_at' => now()])->saveQuietly();

        return $previous !== $result;
    }

    /**
     * @return float|null|false The rating; null when IMDb has none yet (or doesn't know the id);
     *                          false when OMDb couldn't answer (no key, network, quota).
     */
    public function fetch(string $imdbId): float|null|false
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)->retry(2, 500, throw: false)->get(self::ENDPOINT, [
                'i' => $imdbId,
                'apikey' => config('services.omdb.key'),
            ]);
        } catch (Throwable $exception) {
            Log::warning('OMDb request failed', ['imdb_id' => $imdbId, 'error' => $exception->getMessage()]);

            return false;
        }

        if (! $response->ok()) {
            Log::warning('OMDb request failed', ['imdb_id' => $imdbId, 'status' => $response->status()]);

            return false;
        }

        if ($response->json('Response') !== 'True') {
            $error = (string) $response->json('Error', '');
            // An unknown id is an answer; an exhausted quota or a bad key is not.
            if (str_contains(strtolower($error), 'incorrect imdb id') || str_contains(strtolower($error), 'not found')) {
                return null;
            }
            Log::warning('OMDb refused the request', ['imdb_id' => $imdbId, 'error' => $error]);

            return false;
        }

        $rating = $response->json('imdbRating');

        return is_numeric($rating) ? round((float) $rating, 1) : null;
    }
}
