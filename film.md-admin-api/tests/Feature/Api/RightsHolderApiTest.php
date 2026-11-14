<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\ContentCreator;
use App\Models\CreatorFiscalProfile;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RightsHolderApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected Content $carbon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccessControlSeeder::class, TaxonomySeeder::class, ContentSeeder::class]);
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($admin, 'test-admin');
        $this->carbon = Content::query()->where('slug', 'carbon')->firstOrFail();
    }

    public function test_a_holder_is_saved_with_its_legal_details_and_gets_a_matching_fiscal_profile(): void
    {
        $response = $this->postJson('/api/v1/admin/content-creators', [
            'name' => 'Kantora Film',
            'company_name' => 'Kantora Film SRL',
            'person_type' => 'PJ',
            'idno' => '1003600012345',
            'legal_address' => 'str. Ștefan cel Mare 1, Chișinău',
            'iban' => 'md24 ag00 0225 1000 1310 4168',
            'bank_name' => 'Moldova Agroindbank',
            'bank_swift' => 'agrnmd2x',
            'is_vat_payer' => true,
            'vat_code' => '0405123',
            'contact_person' => 'Ion Borș',
            'phone' => '+373 69 000 000',
            'email' => 'office@kantora.md',
        ], $this->auth())->assertCreated();

        $holder = ContentCreator::query()->findOrFail($response->json('creator.id'));
        $this->assertSame('MD24AG000225100013104168', $holder->iban);
        $this->assertSame('AGRNMD2X', $holder->bank_swift);
        $this->assertTrue($holder->is_vat_payer);

        $profile = CreatorFiscalProfile::query()->where('content_creator_id', $holder->id)->sole();
        $this->assertSame('PJ', $profile->person_type);
        $this->assertTrue($profile->is_vat_registered);
        $this->assertSame('1003600012345', $profile->tax_identifier);
        $this->assertSame('MD24AG000225100013104168', $profile->iban);
        $this->assertSame(20.0, (float) $profile->vat_rate);

        $this->getJson('/api/v1/admin/content-creators', $this->auth())
            ->assertOk()
            ->assertJsonFragment(['idno' => '1003600012345', 'is_vat_payer' => true, 'vat_code' => '0405123']);
    }

    public function test_a_holder_without_fiscal_details_gets_no_profile_and_vat_code_needs_vat(): void
    {
        $id = $this->postJson('/api/v1/admin/content-creators', [
            'name' => 'Fără date',
            'vat_code' => '123',
        ], $this->auth())->assertCreated()->json('creator.id');

        $this->assertSame(0, CreatorFiscalProfile::query()->where('content_creator_id', $id)->count());
        $this->assertNull(ContentCreator::query()->findOrFail($id)->vat_code);
    }

    public function test_changing_vat_status_opens_a_new_profile_and_keeps_the_old_terms_for_past_sales(): void
    {
        Carbon::setTestNow('2026-11-13 10:00:00');
        $holder = ContentCreator::query()->create(['name' => 'Titular', 'person_type' => 'PF', 'idno' => '2001234567890']);
        $old = CreatorFiscalProfile::query()->create([
            'content_creator_id' => $holder->id, 'person_type' => 'PF', 'tax_residency' => 'MD',
            'is_vat_registered' => false, 'vat_rate' => 0, 'withholding_enabled' => true, 'withholding_rate' => 12,
            'tax_identifier' => '2001234567890', 'payment_currency' => 'MDL',
            'effective_from' => '2026-01-01', 'status' => 'active',
        ]);

        $this->patchJson("/api/v1/admin/content-creators/{$holder->id}", [
            'name' => 'Titular', 'person_type' => 'PJ', 'idno' => '1003600099999', 'is_vat_payer' => true,
        ], $this->auth())->assertOk();

        $this->assertSame('2026-11-12', Carbon::parse($old->fresh()->effective_until)->toDateString());
        $new = CreatorFiscalProfile::query()->where('content_creator_id', $holder->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-11-13', Carbon::parse($new->effective_from)->toDateString());
        $this->assertTrue($new->is_vat_registered);
        $this->assertSame('PJ', $new->person_type);
        $this->assertTrue($new->withholding_enabled); // carried over, not edited on the holder
        $this->assertSame(12.0, (float) $new->withholding_rate);

        // A second correction the same day fixes that version instead of stacking another.
        $this->patchJson("/api/v1/admin/content-creators/{$holder->id}", [
            'name' => 'Titular', 'iban' => 'MD24AG000225100013104168',
        ], $this->auth())->assertOk();
        $this->assertSame(2, CreatorFiscalProfile::query()->where('content_creator_id', $holder->id)->count());
        $this->assertSame('MD24AG000225100013104168', $new->fresh()->iban);

        // Saving with nothing fiscal changed creates nothing.
        $this->patchJson("/api/v1/admin/content-creators/{$holder->id}", ['name' => 'Titular SRL'], $this->auth())->assertOk();
        $this->assertSame(2, CreatorFiscalProfile::query()->where('content_creator_id', $holder->id)->count());
    }

    public function test_a_title_has_exactly_one_holder_whichever_screen_sets_it(): void
    {
        $a = ContentCreator::query()->create(['name' => 'A']);
        $b = ContentCreator::query()->create(['name' => 'B']);

        $this->patchJson("/api/v1/admin/content/{$this->carbon->id}", $this->contentPayload(['rights_holder_id' => $a->id]), $this->auth())
            ->assertOk()
            ->assertJsonPath('content.rights_holder_id', $a->id)
            ->assertJsonPath('content.rights_holder.name', 'A');

        $this->patchJson("/api/v1/admin/content/{$this->carbon->id}", $this->contentPayload(['rights_holder_id' => $b->id]), $this->auth())
            ->assertOk()
            ->assertJsonPath('content.rights_holder_id', $b->id);
        $this->assertSame([$b->id], $this->holderIdsOf($this->carbon));

        // Picking the title on holder A's page moves it back to A.
        $this->patchJson("/api/v1/admin/content-creators/{$a->id}", ['name' => 'A', 'content_ids' => [$this->carbon->id]], $this->auth())->assertOk();
        $this->assertSame([$a->id], $this->holderIdsOf($this->carbon));

        $this->patchJson("/api/v1/admin/content/{$this->carbon->id}", $this->contentPayload(['rights_holder_id' => null]), $this->auth())
            ->assertOk()
            ->assertJsonPath('content.rights_holder', null);
        $this->assertSame([], $this->holderIdsOf($this->carbon));
    }

    public function test_saving_a_title_without_the_field_keeps_its_holder(): void
    {
        $a = ContentCreator::query()->create(['name' => 'A']);
        ContentCreator::assignTitle($this->carbon->id, $a->id);

        $this->patchJson("/api/v1/admin/content/{$this->carbon->id}", $this->contentPayload(), $this->auth())->assertOk();

        $this->assertSame([$a->id], $this->holderIdsOf($this->carbon));
    }

    public function test_the_database_refuses_a_second_holder(): void
    {
        $a = ContentCreator::query()->create(['name' => 'A']);
        $b = ContentCreator::query()->create(['name' => 'B']);
        ContentCreator::assignTitle($this->carbon->id, $a->id);

        $this->expectException(QueryException::class);
        DB::table('content_creator_assignments')->insert([
            'content_id' => $this->carbon->id, 'content_creator_id' => $b->id, 'role' => 'owner', 'is_primary' => true,
        ]);
    }

    public function test_a_fiscal_profile_entered_in_reporting_updates_the_holder(): void
    {
        $holder = ContentCreator::query()->create(['name' => 'Titular']);

        $this->postJson('/api/v1/admin/reporting/fiscal-profiles', [
            'content_creator_id' => $holder->id, 'person_type' => 'PJ', 'tax_residency' => 'MD',
            'is_vat_registered' => true, 'vat_rate' => 20, 'withholding_enabled' => false, 'withholding_rate' => 0,
            'tax_identifier' => '1003600012345', 'iban' => 'MD24AG000225100013104168', 'payment_currency' => 'MDL',
            'effective_from' => now()->toDateString(),
        ], $this->auth())->assertCreated();

        $holder->refresh();
        $this->assertTrue($holder->is_vat_payer);
        $this->assertSame('1003600012345', $holder->idno);
        $this->assertSame('MD24AG000225100013104168', $holder->iban);
    }

    public function test_the_film_editor_gets_the_holders_to_pick_from(): void
    {
        ContentCreator::query()->create(['name' => 'Kantora Film', 'is_vat_payer' => true]);

        $this->getJson('/api/v1/admin/content/options', $this->auth())
            ->assertOk()
            ->assertJsonFragment(['name' => 'Kantora Film', 'is_vat_payer' => true]);
    }

    public function test_invalid_bank_details_are_rejected_with_a_readable_message(): void
    {
        $this->postJson('/api/v1/admin/content-creators', [
            'name' => 'X', 'iban' => '123', 'bank_swift' => 'AB',
        ], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['iban', 'bank_swift']);
    }

    public function test_the_migration_keeps_the_primary_holder_and_copies_the_current_fiscal_profile(): void
    {
        $migration = require database_path('migrations/2026_11_13_130000_turn_content_creators_into_rights_holders.php');
        $migration->down();

        $now = now();
        $first = DB::table('content_creators')->insertGetId(['name' => 'Oldest', 'created_at' => $now, 'updated_at' => $now]);
        $primary = DB::table('content_creators')->insertGetId(['name' => 'Primary', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('content_creator_assignments')->insert([
            ['content_id' => $this->carbon->id, 'content_creator_id' => $first, 'role' => 'owner', 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now],
            ['content_id' => $this->carbon->id, 'content_creator_id' => $primary, 'role' => 'owner', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('creator_fiscal_profiles')->insert([
            'content_creator_id' => $primary, 'person_type' => 'PF', 'tax_residency' => 'MD', 'is_vat_registered' => true,
            'vat_rate' => 20, 'withholding_enabled' => false, 'withholding_rate' => 0, 'tax_identifier' => '2001234567890',
            'iban' => 'MD24AG000225100013104168', 'payment_currency' => 'MDL', 'effective_from' => '2026-01-01',
            'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $migration->up();

        $this->assertSame([$primary], $this->holderIdsOf($this->carbon));
        $holder = ContentCreator::query()->findOrFail($primary);
        $this->assertSame('PF', $holder->person_type);
        $this->assertTrue($holder->is_vat_payer);
        $this->assertSame('2001234567890', $holder->idno);
        $this->assertSame('MD24AG000225100013104168', $holder->iban);
        $this->assertSame('PJ', ContentCreator::query()->findOrFail($first)->person_type);
    }

    /** @return array<int, int> */
    private function holderIdsOf(Content $content): array
    {
        return DB::table('content_creator_assignments')->where('content_id', $content->id)->pluck('content_creator_id')->map(fn ($id) => (int) $id)->all();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function contentPayload(array $overrides = []): array
    {
        return [
            'type' => Content::TYPE_MOVIE,
            'slug' => 'carbon',
            'default_locale' => 'ro',
            'status' => Content::STATUS_DRAFT,
            'title' => ['ro' => 'Carbon', 'ru' => 'Carbon', 'en' => 'Carbon'],
            'short_description' => ['ro' => 'Scurt.', 'ru' => 'Кратко.', 'en' => 'Short.'],
            'description' => ['ro' => 'Descriere.', 'ru' => 'Описание.', 'en' => 'Description.'],
            'poster_url' => 'https://example.com/poster.jpg',
            'backdrop_url' => 'https://example.com/backdrop.jpg',
            ...$overrides,
        ];
    }
}
