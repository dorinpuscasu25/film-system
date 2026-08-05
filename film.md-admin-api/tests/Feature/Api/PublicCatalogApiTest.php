<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\HomePageSection;
use App\Services\ContentSearchService;
use App\Services\StorefrontCacheService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\HomePageSectionSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_public_home_exposes_a_revalidated_cache_version(): void
    {
        $response = $this->getJson('/api/v1/public/home-version');

        $response
            ->assertOk()
            ->assertJsonPath('version', app(StorefrontCacheService::class)->version());
        $this->assertStringContainsString(
            'no-cache',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    public function test_public_home_is_shared_cacheable_and_varies_by_country(): void
    {
        $response = $this->getJson('/api/v1/public/home?locale=ro');

        $response->assertOk();
        $this->assertSame(
            'public, s-maxage=60, stale-while-revalidate=300',
            $response->headers->get('Cache-Control'),
        );
        $vary = (string) $response->headers->get('Vary');
        $this->assertStringContainsString('CF-IPCountry', $vary);
        $this->assertStringContainsString('X-Country-Code', $vary);
        $this->assertNotNull($response->headers->get('X-Storefront-Cache-Version'));
    }

    public function test_public_home_avoids_query_explosion(): void
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->getJson('/api/v1/public/home?locale=ro')->assertOk();

        $this->assertLessThanOrEqual(15, $queryCount);
    }

    public function test_public_home_handles_empty_translatable_arrays(): void
    {
        DB::table('contents')
            ->where('slug', 'carbon')
            ->update(['tagline' => json_encode([])]);

        $this->getJson('/api/v1/public/home?locale=ro')
            ->assertOk()
            ->assertJsonPath('hero.slug', 'carbon')
            ->assertJsonPath('hero.tagline', '');
    }

    public function test_public_catalog_can_filter_by_type_genre_and_access(): void
    {
        Content::query()
            ->where('slug', 'afacerea-est')
            ->firstOrFail()
            ->formats()
            ->create([
                'quality' => 'HD',
                'format_type' => 'main',
                'bunny_library_id' => '123',
                'bunny_video_id' => 'afacerea-est-hd',
                'stream_url' => 'https://storage.filmoteca.md/playback/afacerea-est-hd.mp4',
                'is_active' => true,
                'is_default' => true,
            ]);

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

    public function test_catalog_facets_are_plain_arrays_safe_for_cache_serialization(): void
    {
        $result = app(ContentSearchService::class)->searchCatalog('ro', [
            'page' => 1,
            'page_size' => 24,
        ]);

        foreach (['genres', 'years', 'countries', 'types', 'access'] as $facet) {
            $this->assertIsArray($result['filters'][$facet] ?? null);
        }
    }

    public function test_public_catalog_can_sort_titles_by_rating(): void
    {
        $this->getJson('/api/v1/public/catalog?locale=ro&sort=rating&page_size=4')
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'carbon')
            ->assertJsonPath('items.0.imdb_rating', 8.3)
            ->assertJsonPath('items.1.slug', 'hackerville')
            ->assertJsonPath('items.1.imdb_rating', 8);
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

    public function test_content_without_rights_windows_remains_available_globally(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();
        $this->assertSame(0, $content->rightsWindows()->count());

        $this->getJson('/api/v1/public/content/carbon?locale=ro', [
            'X-Country-Code' => 'RO',
        ])
            ->assertOk()
            ->assertJsonPath('slug', 'carbon');
    }

    public function test_missing_playback_source_is_not_reported_as_a_territory_error(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();
        $content->formats()->update(['is_active' => false]);
        $content->offers()->update(['playback_url' => null]);
        $content->formats()->create([
            'quality' => 'HD',
            'format_type' => 'trailer',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'carbon-trailer',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->getJson('/api/v1/public/content/carbon?locale=ro', [
            'X-Country-Code' => 'MD',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'playback_source_missing')
            ->assertJsonMissing(['code' => 'territory_restricted']);
    }

    public function test_allow_rights_window_restricts_other_countries(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();
        $content->rightsWindows()->create([
            'country_code' => 'MD',
            'is_allowed' => true,
        ]);

        $this->getJson('/api/v1/public/content/carbon?locale=ro', [
            'X-Country-Code' => 'RO',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'territory_restricted');

        $this->getJson('/api/v1/public/content/carbon?locale=ro', [
            'X-Country-Code' => 'MD',
        ])->assertOk();
    }

    public function test_deny_rights_window_only_blocks_the_matching_country(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();
        $content->rightsWindows()->create([
            'country_code' => 'MD',
            'is_allowed' => false,
        ]);

        $this->getJson('/api/v1/public/content/carbon?locale=ro', [
            'X-Country-Code' => 'MD',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'territory_restricted');

        $this->getJson('/api/v1/public/content/carbon?locale=ro', [
            'X-Country-Code' => 'RO',
        ])->assertOk();
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
