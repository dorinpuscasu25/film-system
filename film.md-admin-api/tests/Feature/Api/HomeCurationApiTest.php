<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\HomePageSection;
use App\Models\PersonalAccessToken;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\StorefrontCacheService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\HomePageSectionSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeCurationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            AccessControlSeeder::class,
            TaxonomySeeder::class,
            ContentSeeder::class,
            HomePageSectionSeeder::class,
        ]);

        $this->admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($this->admin, 'test-admin');
    }

    public function test_admin_can_fetch_home_curation_sections_and_options(): void
    {
        $this->getJson('/api/v1/admin/home-curation', [
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->assertOk()
            ->assertJsonPath('sections.0.section_type', HomePageSection::TYPE_HERO_SLIDER)
            ->assertJsonPath('sections.0.hero_slides.0.content.slug', 'carbon')
            ->assertJsonPath('options.section_types.0.value', HomePageSection::TYPE_HERO_SLIDER)
            ->assertJsonPath('options.contents.0.status', 'published')
            ->assertJsonPath('options.contents.0.is_publicly_visible', false)
            ->assertJsonPath('options.contents.0.visibility_reason', 'Titlul nu are niciun format video activ.');
    }

    public function test_admin_can_update_home_curation_with_manual_and_dynamic_sections(): void
    {
        $comedy = Taxonomy::query()->where('slug', 'comedy')->firstOrFail();
        $cacheVersionBeforeUpdate = app(StorefrontCacheService::class)->version();
        $publicComedy = Content::query()->where('slug', 'afacerea-est')->firstOrFail();
        $publicComedy->formats()->create([
            'quality' => 'HD',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'afacerea-est-hd',
            'stream_url' => 'https://storage.filmoteca.md/playback/afacerea-est-hd.mp4',
            'is_active' => true,
            'is_default' => true,
        ]);

        $response = $this->putJson('/api/v1/admin/home-curation', [
            'sections' => [
                [
                    'name' => 'Hero',
                    'section_type' => HomePageSection::TYPE_HERO_SLIDER,
                    'active' => true,
                    'sort_order' => 0,
                    'hero_slides' => [
                        [
                            'id' => 'hero-slide',
                            'content_id' => 1,
                            'active' => true,
                            'sort_order' => 0,
                            'desktop_image_url' => 'https://example.com/hero-desktop.jpg',
                            'mobile_image_url' => 'https://example.com/hero-mobile.jpg',
                            'title' => ['ro' => 'Hero RO', 'ru' => 'Hero RU', 'en' => 'Hero EN'],
                            'description' => ['ro' => 'Descriere', 'ru' => 'Описание', 'en' => 'Description'],
                            'primary_cta_label' => ['ro' => 'Vezi', 'ru' => 'Смотреть', 'en' => 'Watch'],
                            'secondary_cta_label' => ['ro' => 'Detalii', 'ru' => 'Детали', 'en' => 'Details'],
                        ],
                    ],
                ],
                [
                    'name' => 'Comedy row',
                    'section_type' => HomePageSection::TYPE_CONTENT_CAROUSEL,
                    'active' => true,
                    'sort_order' => 1,
                    'title' => ['ro' => 'Comedii', 'ru' => 'Комедии', 'en' => 'Comedy'],
                    'subtitle' => ['ro' => 'Dinamice', 'ru' => 'Динамика', 'en' => 'Dynamic'],
                    'source_mode' => HomePageSection::SOURCE_DYNAMIC,
                    'limit' => 6,
                    'rule_filters' => [
                        'taxonomy_ids' => [$comedy->id],
                        'content_types' => ['movie'],
                        'access' => HomePageSection::ACCESS_ALL,
                        'sort_mode' => HomePageSection::SORT_RELEASE_YEAR_DESC,
                        'matching_strategy' => HomePageSection::MATCH_ANY,
                        'featured_only' => false,
                        'trending_only' => false,
                    ],
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'sections')
            ->assertJsonPath('sections.1.source_mode', HomePageSection::SOURCE_DYNAMIC)
            ->assertJsonPath('sections.1.rule_filters.taxonomy_ids.0', $comedy->id);

        $this->assertDatabaseCount('home_page_sections', 2);
        $this->assertDatabaseHas('home_page_sections', [
            'name' => 'Comedy row',
            'section_type' => HomePageSection::TYPE_CONTENT_CAROUSEL,
        ]);
        $this->assertGreaterThan(
            $cacheVersionBeforeUpdate,
            app(StorefrontCacheService::class)->version(),
        );

        $savedCarousel = HomePageSection::query()->where('name', 'Comedy row')->firstOrFail();
        $this->assertSame(
            'Comedy',
            $savedCarousel->getTranslation('title', 'en', false),
            json_encode([
                'raw' => $savedCarousel->getRawOriginal('title'),
                'translations' => $savedCarousel->getTranslations('title'),
            ]) ?: 'Unable to inspect saved title.',
        );

        $this->getJson('/api/v1/public/home?locale=en')
            ->assertOk()
            ->assertJsonPath('sections.0.title', 'Comedy')
            ->assertJsonPath('sections.0.source_mode', HomePageSection::SOURCE_DYNAMIC);
    }
}
