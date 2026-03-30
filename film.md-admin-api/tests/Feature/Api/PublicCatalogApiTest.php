<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\HomePageSection;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\HomePageSectionSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccessControlSeeder::class,
            TaxonomySeeder::class,
            ContentSeeder::class,
            HomePageSectionSeeder::class,
        ]);
    }

    public function test_public_home_returns_curated_sections_and_hero_slides(): void
    {
        $this->getJson('/api/v1/public/home?locale=ro')
            ->assertOk()
            ->assertJsonPath('hero.slug', 'carbon')
            ->assertJsonPath('hero_slides.0.title', 'Carbon')
            ->assertJsonPath('sections.0.title', 'În trend acum')
            ->assertJsonPath('sections.1.source_mode', HomePageSection::SOURCE_DYNAMIC)
            ->assertJsonPath('featured.0.hero_desktop_url', 'https://picsum.photos/seed/carbon-hero-desktop/1600/760');
    }

    public function test_public_catalog_can_filter_by_type_genre_and_access(): void
    {
        $this->getJson('/api/v1/public/catalog?locale=en&type=movie&genre=comedy&access=free')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'afacerea-est')
            ->assertJsonPath('items.0.is_free', true)
            ->assertJsonStructure([
                'filters' => [
                    'genres',
                    'years',
                    'countries',
                    'types',
                    'access',
                ],
            ]);
    }

    public function test_public_catalog_can_search_localized_titles_and_people(): void
    {
        $this->getJson('/api/v1/public/catalog?'.http_build_query([
            'locale' => 'ru',
            'query' => 'Карбон',
        ]))
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'carbon');

        $this->getJson('/api/v1/public/catalog?'.http_build_query([
            'locale' => 'en',
            'query' => 'Anna Schumacher',
        ]))
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'hackerville');
    }

    public function test_public_content_returns_localized_detail_payload(): void
    {
        $response = $this->getJson('/api/v1/public/content/carbon?locale=ru');

        $response
            ->assertOk()
            ->assertJsonPath('title', 'Карбон')
            ->assertJsonPath('hero_mobile_url', 'https://picsum.photos/seed/carbon-hero-mobile/720/1080')
            ->assertJsonPath('offers.0.offer_type', 'rental')
            ->assertJsonPath('cast.0.name', 'Dumitru Roman')
            ->assertJsonPath('videos.0.title', 'Official Trailer')
            ->assertJsonPath('badges.0.label', 'Выбор редакции');
    }

    public function test_series_payload_returns_seasons_and_episodes(): void
    {
        $this->getJson('/api/v1/public/content/hackerville?locale=ro')
            ->assertOk()
            ->assertJsonPath('seasons_count', 1)
            ->assertJsonPath('episodes_count', 3)
            ->assertJsonPath('seasons.0.episodes.0.title', 'Episode 1');
    }

    public function test_draft_titles_are_not_exposed_in_public_catalog(): void
    {
        Content::query()->where('slug', 'carbon')->update(['status' => Content::STATUS_DRAFT]);

        $this->getJson('/api/v1/public/content/carbon?locale=ro')
            ->assertNotFound();
    }
}
