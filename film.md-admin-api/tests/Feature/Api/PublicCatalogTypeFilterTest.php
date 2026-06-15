<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Services\ContentSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_movie_bucket_includes_all_non_series_content(): void
    {
        $this->createContent('feature-film', Content::TYPE_MOVIE);
        $this->createContent('documentary-film', Content::TYPE_DOCUMENTARY);
        $this->createContent('short-film', Content::TYPE_SHORT);
        $this->createContent('animation-film', Content::TYPE_ANIMATION);
        $this->createContent('series-title', Content::TYPE_SERIES);

        $result = app(ContentSearchService::class)->searchCatalog('ro', [
            'type' => Content::TYPE_MOVIE,
            'page_size' => 20,
        ]);

        $this->assertSame(
            ['animation-film', 'documentary-film', 'feature-film', 'short-film'],
            $result['items']->pluck('slug')->sort()->values()->all(),
        );
    }

    public function test_series_filter_returns_only_series(): void
    {
        $this->createContent('feature-film', Content::TYPE_MOVIE);
        $this->createContent('series-title', Content::TYPE_SERIES);

        $result = app(ContentSearchService::class)->searchCatalog('ro', [
            'type' => Content::TYPE_SERIES,
            'page_size' => 20,
        ]);

        $this->assertSame(['series-title'], $result['items']->pluck('slug')->values()->all());
    }

    private function createContent(string $slug, string $type): Content
    {
        return Content::query()->create([
            'type' => $type,
            'slug' => $slug,
            'default_locale' => 'ro',
            'status' => Content::STATUS_PUBLISHED,
            'original_title' => str($slug)->replace('-', ' ')->title()->toString(),
            'title' => ['ro' => str($slug)->replace('-', ' ')->title()->toString()],
            'short_description' => ['ro' => 'Descriere scurtă'],
            'description' => ['ro' => 'Descriere completă'],
            'release_year' => 2026,
            'country_code' => 'MD',
            'country_codes' => ['MD'],
            'poster_url' => 'https://example.com/poster.jpg',
            'backdrop_url' => 'https://example.com/backdrop.jpg',
            'subtitle_locales' => ['ro'],
            'available_qualities' => ['HD'],
            'currency' => Content::DEFAULT_CURRENCY,
            'published_at' => now(),
        ]);
    }
}
