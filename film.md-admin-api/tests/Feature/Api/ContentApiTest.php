<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\PersonalAccessToken;
use App\Models\Taxonomy;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentApiTest extends TestCase
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
        ]);

        $this->admin = User::query()->where('email', 'admin@film.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($this->admin, 'test-admin');
    }

    public function test_admin_can_list_content_items(): void
    {
        $this->getJson('/api/v1/admin/content', [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertOk()
            ->assertJsonPath('items.0.slug', 'carbon')
            ->assertJsonPath('filters.types.0.value', Content::TYPE_MOVIE);
    }

    public function test_admin_can_create_content_with_translations_media_and_taxonomies(): void
    {
        $genre = Taxonomy::query()->where('slug', 'drama')->firstOrFail();
        $badge = Taxonomy::query()->where('slug', 'editor-choice')->firstOrFail();
        $imageData = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z4n8AAAAASUVORK5CYII=';

        $response = $this->postJson('/api/v1/admin/content', [
            'type' => Content::TYPE_MOVIE,
            'slug' => 'milika',
            'default_locale' => 'ro',
            'status' => Content::STATUS_DRAFT,
            'original_title' => 'Milika',
            'title' => [
                'ro' => 'Milika',
                'ru' => 'Милика',
                'en' => 'Milika',
            ],
            'tagline' => [
                'ro' => 'Un nou drum.',
                'ru' => 'Новый путь.',
                'en' => 'A new road.',
            ],
            'short_description' => [
                'ro' => 'Scurtă descriere în română.',
                'ru' => 'Краткое описание на русском.',
                'en' => 'Short description in English.',
            ],
            'description' => [
                'ro' => 'Descriere lungă în română.',
                'ru' => 'Подробное описание на русском.',
                'en' => 'Long description in English.',
            ],
            'editor_notes' => [
                'ro' => 'Notă editorială.',
                'ru' => 'Редакционная заметка.',
                'en' => 'Editorial note.',
            ],
            'meta_title' => [
                'ro' => 'Milika film.md',
                'ru' => 'Milika film.md',
                'en' => 'Milika film.md',
            ],
            'meta_description' => [
                'ro' => 'Meta RO',
                'ru' => 'Meta RU',
                'en' => 'Meta EN',
            ],
            'release_year' => 2023,
            'country_code' => 'MD',
            'runtime_minutes' => 85,
            'age_rating' => '12+',
            'poster_url' => $imageData,
            'backdrop_url' => 'https://example.com/backdrop.jpg',
            'hero_desktop_url' => $imageData,
            'hero_mobile_url' => 'https://example.com/hero-mobile.jpg',
            'trailer_url' => 'https://example.com/trailer.mp4',
            'preview_images' => [
                $imageData,
                'https://example.com/preview-2.jpg',
            ],
            'cast_members' => [[
                'name' => 'Ana Ceban',
                'credit_type' => 'lead_actor',
                'character_name' => [
                    'ro' => 'Milika',
                    'ru' => 'Милика',
                    'en' => 'Milika',
                ],
                'avatar_url' => $imageData,
            ]],
            'crew_members' => [[
                'name' => 'Ion Test',
                'credit_type' => 'director',
                'job_title' => [
                    'ro' => 'Regizor',
                    'ru' => 'Режиссёр',
                    'en' => 'Director',
                ],
                'avatar_url' => 'https://example.com/crew.jpg',
            ]],
            'videos' => [[
                'type' => 'trailer',
                'title' => [
                    'ro' => 'Trailer oficial',
                    'ru' => 'Официальный трейлер',
                    'en' => 'Official Trailer',
                ],
                'description' => [
                    'ro' => 'Descriere trailer',
                    'ru' => 'Описание трейлера',
                    'en' => 'Trailer description',
                ],
                'video_url' => 'https://example.com/trailer-main.mp4',
                'thumbnail_url' => $imageData,
                'is_primary' => true,
            ]],
            'subtitle_locales' => ['ro', 'en'],
            'available_qualities' => ['HD', 'Full HD'],
            'is_featured' => true,
            'is_trending' => false,
            'is_free' => false,
            'price_amount' => 4.99,
            'currency' => 'usd',
            'rental_days' => 3,
            'sort_order' => 5,
            'taxonomy_ids' => [$genre->id, $badge->id],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('content.slug', 'milika')
            ->assertJsonPath('content.hero_mobile_url', 'https://example.com/hero-mobile.jpg')
            ->assertJsonPath('content.genres.0.slug', 'drama')
            ->assertJsonPath('content.badges.0.slug', 'editor-choice')
            ->assertJsonPath('content.cast.0.character_name.ro', 'Milika')
            ->assertJsonPath('content.crew.0.job_title.en', 'Director')
            ->assertJsonPath('content.videos.0.title.en', 'Official Trailer');

        $this->assertDatabaseHas('contents', [
            'slug' => 'milika',
            'country_code' => 'MD',
            'status' => Content::STATUS_DRAFT,
        ]);
    }

    public function test_admin_can_delete_content(): void
    {
        $content = Content::query()->where('slug', 'carbon')->firstOrFail();

        $this->deleteJson('/api/v1/admin/content/'.$content->id, [], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertNoContent();

        $this->assertDatabaseMissing('contents', [
            'id' => $content->id,
        ]);
    }
}
