<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\ImdbRatingService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImdbRatingApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccessControlSeeder::class, TaxonomySeeder::class, ContentSeeder::class]);
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($admin, 'test-admin');
        config(['services.omdb.key' => 'test-key']);
    }

    public function test_parses_links_and_bare_ids(): void
    {
        $this->assertSame('tt0111161', ImdbRatingService::parseImdbId('https://www.imdb.com/title/tt0111161/?ref_=nv_sr_srsg_0'));
        $this->assertSame('tt12345678', ImdbRatingService::parseImdbId('https://m.imdb.com/title/TT12345678/'));
        $this->assertSame('tt0111161', ImdbRatingService::parseImdbId(' tt0111161 '));
        $this->assertNull(ImdbRatingService::parseImdbId('https://www.imdb.com/name/nm0000151/'));
        $this->assertNull(ImdbRatingService::parseImdbId(''));
    }

    public function test_creating_content_with_an_imdb_link_fetches_the_rating_and_ignores_a_typed_one(): void
    {
        $this->fakeOmdb(['tt7654321' => '7.8']);

        $this->postJson('/api/v1/admin/content', $this->payload('milika', [
            'imdb_id' => 'https://www.imdb.com/title/tt7654321/?ref_=fn_al_tt_1',
            'imdb_rating' => 9.9,
        ]), $this->auth())
            ->assertCreated()
            ->assertJsonPath('content.imdb_id', 'tt7654321')
            ->assertJsonPath('content.imdb_rating', 7.8);

        $content = Content::query()->where('slug', 'milika')->firstOrFail();
        $this->assertSame(7.8, $content->imdb_rating);
        $this->assertNotNull($content->imdb_rating_synced_at);
        Http::assertSent(fn (Request $request) => $request['i'] === 'tt7654321' && $request['apikey'] === 'test-key');
    }

    public function test_rejects_a_link_that_is_not_an_imdb_title(): void
    {
        Http::fake();

        $this->postJson('/api/v1/admin/content', $this->payload('milika', ['imdb_id' => 'https://www.imdb.com/name/nm0000151/']), $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('imdb_id');
        Http::assertNothingSent();
    }

    public function test_updating_only_refetches_when_the_imdb_link_changes(): void
    {
        $carbon = Content::query()->where('slug', 'carbon')->firstOrFail();
        $this->assertSame(8.3, $carbon->imdb_rating); // typed in before IMDb links existed
        $this->fakeOmdb(['tt1111111' => '8.1']);

        // Saving without a link keeps the old rating and calls nobody.
        $this->patchJson("/api/v1/admin/content/{$carbon->id}", $this->payload('carbon'), $this->auth())->assertOk();
        $this->assertSame(8.3, $carbon->fresh()->imdb_rating);
        Http::assertNothingSent();

        $this->patchJson("/api/v1/admin/content/{$carbon->id}", $this->payload('carbon', ['imdb_id' => 'tt1111111']), $this->auth())
            ->assertOk()
            ->assertJsonPath('content.imdb_rating', 8.1);
        Http::assertSentCount(1);

        // Same link again: no new request.
        $this->patchJson("/api/v1/admin/content/{$carbon->id}", $this->payload('carbon', ['imdb_id' => 'https://www.imdb.com/title/tt1111111/']), $this->auth())->assertOk();
        Http::assertSentCount(1);

        // Link removed: the rating goes with it.
        $this->patchJson("/api/v1/admin/content/{$carbon->id}", $this->payload('carbon', ['imdb_id' => null]), $this->auth())
            ->assertOk()
            ->assertJsonPath('content.imdb_rating', null);
    }

    public function test_sync_command_updates_ratings_and_keeps_them_when_omdb_fails(): void
    {
        $carbon = Content::query()->where('slug', 'carbon')->firstOrFail();
        $carbon->forceFill(['imdb_id' => 'tt1111111'])->save();
        $other = Content::query()->where('slug', '!=', 'carbon')->firstOrFail();
        $other->forceFill(['imdb_id' => 'tt2222222', 'imdb_rating' => 6.0])->save();

        Http::fake(fn (Request $request) => match ($request['i']) {
            'tt1111111' => Http::response(['Response' => 'True', 'imdbRating' => '8.6']),
            default => Http::response(['Response' => 'False', 'Error' => 'Request limit reached!']),
        });

        $this->artisan('imdb:sync-ratings')->assertSuccessful();

        $this->assertSame(8.6, $carbon->fresh()->imdb_rating);
        $this->assertSame(6.0, $other->fresh()->imdb_rating);
    }

    public function test_a_title_without_votes_has_no_rating(): void
    {
        $carbon = Content::query()->where('slug', 'carbon')->firstOrFail();
        $carbon->forceFill(['imdb_id' => 'tt1111111'])->save();
        $this->fakeOmdb(['tt1111111' => 'N/A']);

        $this->artisan('imdb:sync-ratings')->assertSuccessful();

        $this->assertNull($carbon->fresh()->imdb_rating);
    }

    public function test_sync_command_refuses_to_run_without_a_key(): void
    {
        config(['services.omdb.key' => null]);
        Http::fake();

        $this->artisan('imdb:sync-ratings')->assertFailed();
        Http::assertNothingSent();
    }

    /** @param array<string, string> $ratings imdb id => OMDb's imdbRating */
    private function fakeOmdb(array $ratings): void
    {
        Http::fake(fn (Request $request) => isset($ratings[$request['i']])
            ? Http::response(['Response' => 'True', 'imdbRating' => $ratings[$request['i']]])
            : Http::response(['Response' => 'False', 'Error' => 'Incorrect IMDb ID.']));
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function payload(string $slug, array $overrides = []): array
    {
        return [
            'type' => Content::TYPE_MOVIE,
            'slug' => $slug,
            'default_locale' => 'ro',
            'status' => Content::STATUS_DRAFT,
            'title' => ['ro' => ucfirst($slug), 'ru' => ucfirst($slug), 'en' => ucfirst($slug)],
            'short_description' => ['ro' => 'Scurt.', 'ru' => 'Кратко.', 'en' => 'Short.'],
            'description' => ['ro' => 'Descriere.', 'ru' => 'Описание.', 'en' => 'Description.'],
            'poster_url' => 'https://example.com/poster.jpg',
            'backdrop_url' => 'https://example.com/backdrop.jpg',
            ...$overrides,
        ];
    }
}
