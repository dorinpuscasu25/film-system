<?php

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\Taxonomy;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxonomyApiTest extends TestCase
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
        ]);

        $this->admin = User::query()->where('email', 'admin@film.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($this->admin, 'test-admin');
    }

    public function test_admin_can_list_taxonomies_grouped_by_type(): void
    {
        $this->getJson('/api/v1/admin/taxonomies', [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertOk()
            ->assertJsonPath('types.0.value', Taxonomy::TYPE_GENRE)
            ->assertJsonPath('taxonomies.genre.0.name.ro', 'Comedie')
            ->assertJsonPath('taxonomies.badge.0.color', '#0F172A');
    }

    public function test_admin_can_create_a_badge_taxonomy_with_translations(): void
    {
        $response = $this->postJson('/api/v1/admin/taxonomies', [
            'type' => Taxonomy::TYPE_BADGE,
            'slug' => 'critics-pick',
            'active' => true,
            'color' => '#16A34A',
            'name' => [
                'ro' => 'Alegerea criticilor',
                'ru' => 'Выбор критиков',
                'en' => 'Critics pick',
            ],
            'description' => [
                'ro' => 'Badge pentru titluri apreciate de critici.',
                'ru' => 'Бейдж для тайтлов, оценённых критиками.',
                'en' => 'Badge for critically acclaimed titles.',
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('taxonomy.type', Taxonomy::TYPE_BADGE)
            ->assertJsonPath('taxonomy.name.en', 'Critics pick')
            ->assertJsonPath('taxonomy.color', '#16A34A');

        /** @var Taxonomy $taxonomy */
        $taxonomy = Taxonomy::query()->where('slug', 'critics-pick')->firstOrFail();

        $this->assertSame('Alegerea criticilor', $taxonomy->getTranslation('name', 'ro'));
        $this->assertSame('Critics pick', $taxonomy->getTranslation('name', 'en'));
    }

    public function test_badges_require_a_color_and_all_locales(): void
    {
        $response = $this->postJson('/api/v1/admin/taxonomies', [
            'type' => Taxonomy::TYPE_BADGE,
            'slug' => 'missing-data',
            'name' => [
                'ro' => 'Lipsă',
                'ru' => '',
                'en' => 'Missing',
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name.ru', 'color']);
    }

    public function test_admin_can_update_a_taxonomy_translation_payload(): void
    {
        $taxonomy = Taxonomy::query()->where('type', Taxonomy::TYPE_GENRE)->where('slug', 'comedy')->firstOrFail();

        $response = $this->patchJson('/api/v1/admin/taxonomies/'.$taxonomy->id, [
            'type' => Taxonomy::TYPE_GENRE,
            'slug' => 'comedy',
            'active' => true,
            'name' => [
                'ro' => 'Comedie actualizată',
                'ru' => 'Обновлённая комедия',
                'en' => 'Updated comedy',
            ],
            'description' => [
                'ro' => 'Descriere actualizată.',
                'ru' => 'Обновлённое описание.',
                'en' => 'Updated description.',
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('taxonomy.name.ro', 'Comedie actualizată');

        $taxonomy->refresh();

        $this->assertSame('Updated comedy', $taxonomy->getTranslation('name', 'en'));
        $this->assertSame('Descriere actualizată.', $taxonomy->getTranslation('description', 'ro'));
    }

    public function test_admin_can_delete_a_taxonomy(): void
    {
        $taxonomy = Taxonomy::query()->where('type', Taxonomy::TYPE_TAG)->where('slug', 'family-night')->firstOrFail();

        $this->deleteJson('/api/v1/admin/taxonomies/'.$taxonomy->id, [], [
            'Authorization' => 'Bearer '.$this->token,
        ])->assertNoContent();

        $this->assertDatabaseMissing('taxonomies', [
            'id' => $taxonomy->id,
        ]);
    }
}
